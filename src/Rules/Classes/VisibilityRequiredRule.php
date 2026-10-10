<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\InterfaceNode;
use function count;


/**
 * Every property, method and constant of a class, interface, trait or enum declares its visibility (`var` becomes
 * `public`, a set visibility being one); the order of the modifiers is `ModifierOrderRule`'s. A method of an
 * interface, public whatever it says, follows `classes.visibility.interfaceMethod`.
 */
#[RuleInfo(Stage::Structure)]
final class VisibilityRequiredRule extends NodeRule
{
	private const Required = 'required';
	private const Forbidden = 'forbidden';
	private const Keep = 'keep';
	private const Members = 'classes.visibility.member';
	private const InterfaceMethod = 'classes.visibility.interfaceMethod';

	private bool $members = true;

	private string $interfaceMethod = self::Required;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Members, Domain::state('required'), 'The visibility every property, method and constant declares, `var` becoming `public` and a set visibility counting as one'),
			new Decision(self::InterfaceMethod, Domain::state('required', 'forbidden'), 'The `public` of a method of an interface, which is public whatever it says'),
		];
	}


	public function configure(Values $values): void
	{
		$this->members = !$values->isKept(self::Members);
		$interfaceMethod = $values->get(self::InterfaceMethod);
		$this->interfaceMethod = $interfaceMethod->isKept() ? self::Keep : $interfaceMethod->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [PropertyNode::class, MethodNode::class, ClassConstNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PropertyNode && !$node instanceof MethodNode && !$node instanceof ClassConstNode) {
			return;
		}

		$interfaceMethod = $node instanceof MethodNode && $node->parent?->parent instanceof InterfaceNode;
		$mode = match (true) {
			$interfaceMethod => $this->interfaceMethod,
			$this->members => self::Required,
			default => self::Keep,
		};
		if ($mode !== self::Keep) {
			$this->writeVisibility($node, $mode, $interfaceMethod, $context);
		}
	}


	private function writeVisibility(
		PropertyNode|MethodNode|ClassConstNode $node,
		string $mode,
		bool $interfaceMethod,
		RuleContext $context,
	): void
	{
		$tokens = $node->modifiers->getTokens();
		$desired = [];
		$hasVisibility = false;
		foreach ($tokens as $token) {
			$visibility = MemberModifiers::rank($token) === MemberModifiers::Visibility;
			// a set visibility alone is one too, the `public` it implies being `classes.visibility.publicWithSet`
			$hasVisibility = $hasVisibility || $visibility || MemberModifiers::rank($token) === MemberModifiers::SetVisibility;
			if (!$visibility || $mode !== self::Forbidden) {
				$desired[] = $token->is(Token::Var) && $mode === self::Required ? 'public' : $token->text;
			}
		}

		if (!$hasVisibility && $mode === self::Required) {
			// before what follows the visibility, so that modifiers already in order stay so
			$position = count($tokens);
			foreach ($tokens as $i => $token) {
				if (MemberModifiers::rank($token) > MemberModifiers::Visibility) {
					$position = $i;
					break;
				}
			}
			array_splice($desired, $position, 0, ['public']);
		}

		if ($desired === array_map(fn(Token $t) => $t->text, $tokens)) {
			return;
		}

		$member = MemberModifiers::describeMember($node);
		[$message, $decision] = match (true) {
			$mode === self::Forbidden => ["The $member of an interface must not declare its visibility.", self::InterfaceMethod],
			$hasVisibility && !array_any($tokens, fn(Token $token) => strtolower($token->text) === 'var') => ["The modifiers of the $member must be written `" . implode(' ', $desired) . '`.', self::Members],
			default => ["The $member must declare its visibility.", $interfaceMethod ? self::InterfaceMethod : self::Members],
		};
		$first = $tokens[0] ?? match (true) {
			$node instanceof MethodNode => $node->functionKeyword,
			$node instanceof ClassConstNode => $node->constKeyword,
			default => $node->type?->getFirstToken() ?? $node->items->getFirstToken(),
		};
		if ($first !== null && $context->report($first, $message, decision: $decision)) {
			MemberModifiers::write($node, $desired);
		}
	}
}
