<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DestructuringNode, Expression, ExpressionNode};
use PhpSyntax\Nodes\Scalar\NullNode;


/**
 * An array turns a null key into an empty string, and PHP 8.5 deprecated letting it: `[null => 1]`, `$a[null]`
 * and `array_key_exists(null, $a)` say `['' => 1]`, `$a['']` and `array_key_exists('', $a)`. Only a null the
 * code writes out is read; a null that arrives in a variable the code does not show. An object implementing
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
		if (!$key instanceof NullNode) {
			return;
		}

		$array = match (true) {
			$node instanceof Expression\ArrayAccessNode => self::isArray($node->expression, $context),
			$node instanceof ArrayItemNode && $node->parent?->parent instanceof DestructuringNode => self::isArray(self::findDestructured($node->parent->parent), $context),
			default => Tristate::Yes,
		};
		$uncertainty = $node instanceof Expression\FunctionCallNode ? GlobalCalls::findUncertainty($node, $context) : null;
		if (
			$array === Tristate::No
			|| !$context->report(
				$key,
				"The `null` key must be written `''`, which is what the array makes of it.",
				risk: $array === Tristate::Maybe ? Risk::TypeUnknown : ($uncertainty === null ? null : Risk::NameUncertain),
				because: $array === Tristate::Maybe ? 'an object implementing `ArrayAccess` gets the key as it is written' : $uncertainty,
			)
		) {
			return;
		}

		$key->replaceWith((new Builder)->expression("''"));
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
