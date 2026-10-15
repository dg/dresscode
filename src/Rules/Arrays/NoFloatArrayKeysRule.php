<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArrayItemNode, DestructuringNode, Expression};
use PhpSyntax\Nodes\Scalar\FloatNode;


/**
 * An array turns a float key into an integer, cutting off the fraction, and PHP 8.1 deprecated letting it where a
 * fraction is lost: `$a[(int) $ratio]` and `[1 => 'x']` say what `$a[$ratio]` and `[1.5 => 'x']` did. A division,
 * whose result is an integer or a float, and a key the types tell is a float are cast; one they tell only may be a
 * float is left, since they say so of nearly every sum of integers, which may overflow. An object implementing
 * `ArrayAccess` gets the key as it is written, so an offset of what may be one is risky and of what the types tell is
 * one stays. Without the types a literal and a division are read.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class])]
final class NoFloatArrayKeysRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.php.floatArrayKey',
			Domain::state('forbidden'),
			'`$a[(int) $ratio]` and `[1 => \'x\']`, where a float key loses its fraction, deprecated by PHP 8.1',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\ArrayAccessNode::class, ArrayItemNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$key = match (true) {
			$node instanceof Expression\ArrayAccessNode => $node->index,
			$node instanceof ArrayItemNode => $node->key,
			default => null,
		};
		$types = $context->findAnalysis(Types::class);
		if ($key instanceof FloatNode) {
			if ((float) (int) $key->toValue() === $key->toValue()) {
				return; // a whole number loses nothing
			}
		} elseif (
			$key === null
			|| (!($key instanceof Expression\BinaryOpNode && $key->operator->text === '/')
				&& $types?->isOfType($key, 'float') !== Tristate::Yes)
		) {
			return;
		}

		$array = match (true) {
			$node instanceof Expression\ArrayAccessNode => $types?->isOfType($node->expression, 'array') ?? Tristate::Maybe,
			$node->parent?->parent instanceof DestructuringNode => Tristate::Maybe,
			default => Tristate::Yes,
		};
		if (
			$array === Tristate::No
			|| !$context->report(
				$key,
				'The float key loses its fraction, which PHP 8.1 deprecated; the integer it becomes must be written.',
				risk: $array === Tristate::Yes ? null : Risk::TypeUnknown,
				because: $array === Tristate::Yes ? null : 'an object implementing `ArrayAccess` gets the key as it is written',
			)
		) {
			return;
		}

		$key->replaceWith($key instanceof FloatNode
			? (new Builder)->expression((string) (int) $key->toValue())
			: (new Builder)->expression('(int) $key', key: $key->withoutEdgeTrivia()));
	}
}
