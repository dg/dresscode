<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\{ModifiersNode, ParameterNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode};


/**
 * `private` where `protected` can reach nothing more: in an enum, which no class extends, and in a final class that has
 * no parent and uses no trait, where nothing could override or call the member from elsewhere. A method, a constant,
 * a property and a promoted one, and the `protected(set)` of a property; a final constant keeps `protected`, PHP
 * refusing a private one.
 */
#[RuleInfo(Stage::Structure)]
final class PrivateForProtectedRule extends NodeRule
{
	private const InEnum = 'classes.protectedInEnum';
	private const InFinalClass = 'classes.protectedInFinalClass';

	private bool $inEnum = false;

	private bool $inFinalClass = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::InEnum, Domain::state('forbidden'), 'A `protected` member of an enum, which is `private`'),
			new Decision(
				self::InFinalClass,
				Domain::state('forbidden'),
				'A `protected` member of a final class with no parent and no trait, which is `private`',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->inEnum = !$values->isKept(self::InEnum);
		$this->inFinalClass = !$values->isKept(self::InFinalClass);
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->inEnum ? [EnumNode::class] : []),
			...($this->inFinalClass ? [ClassNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof EnumNode) {
			$this->privatize($node->members->getItems(), 'an enum', self::InEnum, $context);

		} elseif (
			$node instanceof ClassNode
			&& $node->modifiers->final
			&& $node->extends === null
			&& !array_any($node->members->getItems(), fn(Node $member) => $member instanceof TraitUseNode)
		) {
			$this->privatize($node->members->getItems(), 'a final class', self::InFinalClass, $context);
		}
	}


	/** @param  list<Node>  $members */
	private function privatize(array $members, string $owner, string $decision, RuleContext $context): void
	{
		foreach ($members as $member) {
			$modifiers = match (true) {
				$member instanceof ClassConstNode => $member->modifiers->final ? [] : [$member->modifiers],
				$member instanceof PropertyNode => [$member->modifiers],
				$member instanceof MethodNode => [
					$member->modifiers,
					...array_map(fn(ParameterNode $parameter) => $parameter->modifiers, $member->parameters->getItems()),
				],
				default => [],
			};
			foreach (array_filter($modifiers, fn(?ModifiersNode $node) => $node !== null) as $node) {
				foreach ($node->getTokens() as $token) {
					$private = match (strtolower($token->text)) {
						'protected' => 'private',
						'protected(set)' => 'private(set)',
						default => null,
					};
					if (
						$private !== null
						&& $context->report(
							$token,
							"The `$token->text` member of $owner must be `$private`.",
							decision: $decision,
						)
					) {
						$token->replaceWith(Token::fromText($private));
					}
				}
			}
		}
	}
}
