<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\{ArgumentNode, Expression};
use function count;


/**
 * A cast instead of the conversion functions: `(int) $x`, not `intval($x)`; a call with a base or
 * other extra arguments stays.
 */
#[RuleInfo(
	'dresscode/noConversionFunctions',
	Stage::Structure,
	description: 'Uses a cast instead of `intval()` and friends',
	group: RuleGroup::OptimizedCalls,
)]
final class NoConversionFunctionsRule extends NodeRule
{
	private const Casts = [
		'intval' => 'int',
		'floatval' => 'float',
		'doubleval' => 'float',
		'strval' => 'string',
		'boolval' => 'bool',
	];


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode) {
			return;
		}

		$function = GlobalCalls::findFunction($node, self::Casts, $context);
		$cast = $function === null ? null : self::Casts[$function];

		$args = $node->arguments->items->getItems();
		$arg = $args[0] ?? null;
		if (
			$cast === null
			|| count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name !== null
			|| $arg->ampersand !== null
			|| $arg->ellipsis !== null
			|| !$context->report(
				$node,
				"The conversion function must be written as the `($cast)` cast",
				risk: ($uncertainty = GlobalCalls::findUncertainty($node, $context)) === null ? null : Risk::NameUncertain,
				because: $uncertainty,
			)
		) {
			return;
		}

		$operand = clone $arg->value;
		$operand->setEdgeTrivia(leading: []);
		$operand->getLastToken()?->removeTrailingWhitespace();
		$template = (new Parser)->parseExpression("($cast) 0");
		assert($template instanceof Expression\CastNode);
		$template->expression->replaceWithExpression($operand);
		$node->replaceWith($template);
	}
}
