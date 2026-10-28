<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, GlobalCalls, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, UnaryOpNode};


/**
 * Whether an array is empty is `$a === []`, not the count of it compared with zero: `count($a) > 0` is `$a !== []`,
 * `!sizeof($a)` is `$a === []`. The comparisons read are those that ask exactly that, with 0 or 1 on either side;
 * `count($a) >= 0` asks nothing and stays.
 *
 * The fix is safe where the types say the value is an array: `count()` counts a Countable object too, which is never
 * `[]`, and throws on anything else, `null` among it, which `===` only finds unequal. Without them, or where the value
 * may be something else, the fix is risky; a value that is surely no array is left alone.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class, NameResolver::class])]
final class NoManualEmptyArrayTestsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('hygiene.countEmptyTest', Domain::state('forbidden'), '`count($a) === 0` is `$a === []`')];
	}


	public function getVisitedNodes(): array
	{
		return [BinaryOpNode::class, UnaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryOpNode && !$node instanceof UnaryOpNode) {
			return;
		}

		$test = EmptinessTest::find($node);
		if (
			$test === null
			|| ($function = GlobalCalls::findFunction($test->call, ['count' => true, 'sizeof' => true], $context)) === null
			|| ($array = $context->findAnalysis(Types::class)?->isOfType($test->value, 'array') ?? Tristate::Maybe) === Tristate::No
		) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertainty($test->call, $context);
		$because = array_filter([
			$array === Tristate::Maybe
				? 'the value may be no array: `count()` throws on `null` and counts a Countable object, neither of which is `[]`'
				: null,
			$uncertainty,
		]);
		$risk = match (true) {
			$array === Tristate::Maybe => Risk::TypeUnknown,
			$uncertainty !== null => Risk::NameUncertain,
			default => null,
		};
		$fixable = !$node->getFirstToken()->hasCommentUpTo($test->value->getFirstToken())
			&& !$test->value->getLastToken()->hasCommentUpTo($node->getLastToken());
		if (!$context->report(
			$node,
			'The empty array must be tested with ' . ($test->empty ? '`=== []`' : '`!== []`') . ", not through `$function()`.",
			fixable: $fixable,
			risk: $risk,
			because: $because === [] ? null : implode('; ', $because),
		)) {
			return;
		}

		// an equality binds more loosely than the comparison or the negation it takes the place of
		$node->replaceWithExpression((new Builder)->binary($test->value, $test->empty ? '===' : '!==', []));
	}
}
