<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\ExpressionNode;


/**
 * A loose comparison of a number with a string that is no number, which PHP 8.0 turned around: `0 == 'a'` was true and
 * is false, the number now compared as a string. Only a comparison the types tell is of a number and such a string is
 * reported, the project deciding which of the two meanings it wants; there is no fix.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class])]
final class NoLooseNumberComparisonsRule extends NodeRule
{
	private const Operators = ['==', '!=', '<>', '<', '<=', '>', '>=', '<=>'];


	public static function getDecisions(): array
	{
		return [new Decision(
			'mistakes.looseNumberComparison',
			Domain::state('forbidden'),
			'`0 == \'a\'`, a number compared loosely with a string that is no number, whose answer PHP 8.0 turned around, which is only reported',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [BinaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryOpNode || !in_array($node->operator->text, self::Operators, true)) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		if (
			(self::isNumber($node->left, $types) && self::isNonNumericString($node->right, $types))
			|| (self::isNumber($node->right, $types) && self::isNonNumericString($node->left, $types))
		) {
			$context->report(
				$node->operator,
				'A number is compared with a string that is no number, which PHP 8.0 compares as strings.',
				fixable: false,
			);
		}
	}


	private static function isNumber(ExpressionNode $expression, Types $types): bool
	{
		return $types->isOfType($expression, 'int|float') === Tristate::Yes;
	}


	private static function isNonNumericString(ExpressionNode $expression, Types $types): bool
	{
		return $types->isOfType($expression, 'string') === Tristate::Yes
			&& $types->isOfType($expression, 'numeric-string') === Tristate::No;
	}
}
