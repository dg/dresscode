<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, GlobalCalls, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DestructuringNode, Expression, ExpressionNode};
use PhpSyntax\Nodes\Scalar\NullNode;


/**
 * An array turns a null key into an empty string, and PHP 8.5 deprecated letting it: `[null => 1]`, `$a[null]`
 * and `array_key_exists(null, $a)` say `['' => 1]`, `$a['']` and `array_key_exists('', $a)`. A key the types tell is an
 * integer, a string or null is written with the fallback, `$a[$key ?? '']`, which is risky, the types not telling
 * whether a null ever comes; a key they know nothing of, and without the types any but a null the code writes out, is
 * left alone. An object implementing
 * `ArrayAccess` gets the null as it is written, so an offset of what may be one is risky and of what the types
 * tell is one stays; without the types, an array is not told from such an object. A key of destructuring reads an
 * offset of the value destructured, so it is taken as such an offset is.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class, NameResolver::class])]
final class NoNullArrayKeysRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.nullArrayKey', Domain::state('forbidden'), '`[null => 1]` is `[\'\' => 1]`, which it becomes')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\ArrayAccessNode::class, ArrayItemNode::class, Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$key = match (true) {
			$node instanceof Expression\ArrayAccessNode => $node->index,
			$node instanceof ArrayItemNode => $node->key,
			$node instanceof Expression\FunctionCallNode
				&& !$node->arguments->isPartialApplication()
				&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node, 'array_key_exists')
				=> $node->arguments->findArgument('key', 0)?->value,
			default => null,
		};
		$types = $context->findAnalysis(Types::class);
		$null = match (true) {
			$key instanceof NullNode => Tristate::Yes,
			// a key the types know nothing of is not taken for one that may be null
			$key === null || $types === null || $types->isOfType($key, 'int|string|null') !== Tristate::Yes => Tristate::No,
			default => $types->isOfType($key, 'null'),
		};
		if ($null === Tristate::No) {
			return;
		}

		$array = match (true) {
			$node instanceof Expression\ArrayAccessNode => self::isArray($node->expression, $context),
			$node instanceof ArrayItemNode && $node->parent?->parent instanceof DestructuringNode => self::isArray(
				self::findDestructured($node->parent->parent),
				$context,
			),
			default => Tristate::Yes,
		};
		assert($key !== null);
		$uncertainty = $node instanceof Expression\FunctionCallNode ? GlobalCalls::findUncertainty($node, $context) : null;
		if (
			$array === Tristate::No
			|| !$context->report(
				$key,
				$null === Tristate::Yes
					? "The `null` key must be written `''`, which is what the array makes of it."
					: "The key may be null, which the array makes `''`, so the fallback must be written.",
				risk: match (true) {
					$array === Tristate::Maybe, $null === Tristate::Maybe => Risk::TypeUnknown,
					$uncertainty !== null => Risk::NameUncertain,
					default => null,
				},
				because: match (true) {
					$array === Tristate::Maybe => 'an object implementing `ArrayAccess` gets the key as it is written',
					$null === Tristate::Maybe => 'the types do not tell whether the key is ever null, where the fallback is only noise',
					default => $uncertainty,
				},
			)
		) {
			return;
		}

		$key->replaceWith($null === Tristate::Yes
			? (new Builder)->expression("''")
			: (new Builder)->expression("\$key ?? ''", key: $key->withoutEdgeTrivia()));
	}


	/** Whether the expression is an array; maybe for one the types do not tell or that is not known. */
	private static function isArray(?ExpressionNode $expression, RuleContext $context): Tristate
	{
		return $expression === null
			? Tristate::Maybe
			: $context->findAnalysis(Types::class)?->isOfType($expression, 'array') ?? Tristate::Maybe;
	}


	/** What the destructuring takes apart: the value assigned to it, null where it is the item of another one or of `foreach`. */
	private static function findDestructured(DestructuringNode $destructuring): ?ExpressionNode
	{
		$assignment = $destructuring->parent;
		return $assignment instanceof Expression\AssignmentNode && $assignment->target === $destructuring
			? $assignment->expression
			: null;
	}
}
