<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\{PhpSignatures, Types};
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token, Visibility};
use PhpSyntax\Nodes\{AnonymousClassNode, ParameterNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\ClassNode;
use function count;


/**
 * A property the constructor sets and nothing else touches is `readonly`, which says so and makes PHP keep
 * it. The property must carry a type and no default value, which is what readonly takes, and the constructor
 * itself must assign it through `$this` exactly once and outside a loop: a closure inside the constructor is not it,
 * a write to a clone is what readonly refuses, and a second assignment fails. A promoted parameter is read the same
 * way, promotion being the assignment it needs. Nothing may change the value in place either: a combined
 * assignment, an element written, a reference taken, a parameter taking it by reference. Where the code does not
 * show whether a call takes it by reference, the fix is risky. From PHP 8.6 readonly takes a default value too,
 * which is then the initialization, so a property with one is readonly where nothing writes it at all, the
 * constructor included.
 *
 * Who else may write the property the code shows by its visibility: a private one nothing outside the class
 * reaches, whatever the class is, and any other one only where the class is final and extends nothing. A property
 * that is not private is a risky subject in such a class, the code that writes it from outside not being in the
 * file; one of a class that can be extended or that has a parent is left alone altogether, because a child
 * redeclaring it, or a parent declaring it without readonly, would then be a fatal error rather than a changed
 * behaviour. A class using a trait is left alone too: the properties of the trait are not in sight, and one it
 * declares as well would then be incompatible, which is a fatal error.
 * A hooked property cannot be readonly at all.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=8.1'],
	analyses: [PhpSignatures::class, Types::class, NameResolver::class],
)]
final class ReadonlyForUnwrittenPropertyRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.readonlyProperties', Domain::adopted(), 'A property written only in the constructor, or not at all')];
	}


	public function getVisitedNodes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof AnonymousClassNode)
			|| $node->modifiers->readonly
			|| array_any($node->members->getItems(), fn($member) => $member instanceof TraitUseNode)
		) {
			return;
		}

		$constructor = null;
		foreach ($node->members as $member) {
			if ($member instanceof MethodNode && $member->isConstructor()) {
				$constructor = $member;
			}
		}

		$final = $node instanceof AnonymousClassNode || $node->modifiers->final;
		foreach ($node->members as $member) {
			if ($member instanceof PropertyNode && count($member->items) === 1) {
				$item = $member->items->getItems()[0];
				$this->markReadonly($member, $item->plainName, $item->default !== null, $node, $constructor, $final, $context);
			}
		}

		foreach ($constructor?->parameters->getItems() ?? [] as $parameter) {
			if ($parameter->promoted && $parameter->variable->plainName !== null) {
				$this->markReadonly($parameter, $parameter->variable->plainName, false, $node, $constructor, $final, $context);
			}
		}
	}


	/** Marks the member readonly where nothing but the constructor writes it, or nothing at all with a default value. */
	private function markReadonly(
		PropertyNode|ParameterNode $member,
		string $name,
		bool $hasDefault,
		ClassNode|AnonymousClassNode $class,
		?MethodNode $constructor,
		bool $final,
		RuleContext $context,
	): void
	{
		$modifiers = $member->modifiers;
		if (
			$modifiers->readonly
			|| $modifiers->static
			|| ($hasDefault ? version_compare($context->phpVersion, '8.6', '<') : $constructor === null)
			|| $member->type === null
			|| $member->hooks !== null
			|| ($modifiers->visibility !== Visibility::Private && (!$final || $class->extends !== null))
		) {
			return;
		}

		$writes = PropertyWrites::fromClass($class, $name, $constructor, $context);
		if ($writes === null || $writes->initializations !== ($member instanceof ParameterNode || $hasDefault ? 0 : 1)) {
			return;
		}

		$risk = match (true) {
			$modifiers->visibility !== Visibility::Private => [Risk::BehaviorChanges, 'code outside the file may write it'],
			$writes->uncertain => [Risk::TypeUnknown, 'a call may take it by reference'],
			default => [null, null],
		};
		$message = $hasDefault
			? "The property `\$$name`, which nothing writes, must be readonly."
			: "The property `\$$name`, written only by the constructor, must be readonly.";
		if (!$context->report($member, $message, risk: $risk[0], because: $risk[1])) {
			return;
		}

		if ($var = $modifiers->findToken(Token::Var)) { // readonly does not go with var
			$modifiers->removeToken($var);
			$modifiers->append(Token::fromText('public'));
		}

		$modifiers->append(Token::fromText('readonly'));
	}
}
