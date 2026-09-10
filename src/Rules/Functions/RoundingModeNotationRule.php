<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Parser;
use PhpSyntax\Token;


/**
 * The rounding mode of `round()` is a case of the `RoundingMode` enum of PHP 8.4, not one of the four
 * constants that stood for it: `PHP_ROUND_HALF_UP` is `RoundingMode::HalfAwayFromZero`, which says what it
 * does with a half. Only the mode of a `round()` call is read; elsewhere the constants are plain integers
 * and the enum is not one.
 */
#[RuleInfo(
	'dresscode/rounding-mode-notation',
	Stage::Structure,
	description: 'Writes the rounding mode of round() as a case of the RoundingMode enum',
	group: Group::Modernization,
	requires: ['php' => '>=8.4'],
)]
final class RoundingModeNotationRule extends NodeRule
{
	private const Modes = [
		'PHP_ROUND_HALF_UP' => 'HalfAwayFromZero',
		'PHP_ROUND_HALF_DOWN' => 'HalfTowardsZero',
		'PHP_ROUND_HALF_EVEN' => 'HalfEven',
		'PHP_ROUND_HALF_ODD' => 'HalfOdd',
	];


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\FunctionCallNode
			|| $node->arguments->isPartialApplication()
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node, 'round')
		) {
			return;
		}

		$mode = $node->arguments->findArgument('mode', 2)?->value;
		if (!$mode instanceof Expression\ConstantFetchNode) {
			return;
		}

		$case = self::Modes[$context->getAnalysis(NameResolver::class)->resolveConstant($mode->name)] ?? null;
		if (
			$case === null
			|| $mode->hasComment()
			|| !$context->report($mode, "The rounding mode must be written RoundingMode::$case")
		) {
			return;
		}

		$mode->replaceWith((new Parser)->parseExpression("\\RoundingMode::$case"));
	}
}
