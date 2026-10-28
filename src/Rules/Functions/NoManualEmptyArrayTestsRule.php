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
 * The types decide it, which is what the count alone cannot: only a value that is an array for sure is compared
 * with `[]`, because `count()` counts a Countable object too, which is never `[]`, and throws on anything else.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, NameResolver::class])]
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
			|| $context->getAnalysis(Types::class)->isOfType($test->value, 'array') !== Tristate::Yes
		) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertainty($test->call, $context);
		$fixable = !$node->getFirstToken()->hasCommentUpTo($test->value->getFirstToken())
			&& !$test->value->getLastToken()->hasCommentUpTo($node->getLastToken());
		if (!$context->report(
			$node,
			'The empty array must be tested with ' . ($test->empty ? '`=== []`' : '`!== []`') . ", not through `$function()`.",
			fixable: $fixable,
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			return;
		}

		// an equality binds more loosely than the comparison or the negation it takes the place of
		$node->replaceWithExpression((new Builder)->binary($test->value, $test->empty ? '===' : '!==', []));
	}
}
