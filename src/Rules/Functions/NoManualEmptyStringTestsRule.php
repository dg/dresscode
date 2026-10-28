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
 * Whether a string is empty is `$s === ''`, not the length of it compared with zero: `strlen($s) > 0` is
 * `$s !== ''`, `!mb_strlen($s)` is `$s === ''`. The comparisons read are those that ask exactly that, with 0 or 1 on
 * either side; `strlen($s) >= 0` asks nothing and stays.
 *
 * The types decide it, which is what the length alone cannot: only a value that is a string for sure is compared
 * with `''`, because `strlen()` counts `null` as empty, an int as its digits and a Stringable object as its text,
 * all of which `===` sees differently.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, NameResolver::class])]
final class NoManualEmptyStringTestsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('hygiene.strlenEmptyTest', Domain::state('forbidden'), '`strlen($s) === 0` is `$s === \'\'`')];
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
			|| ($function = GlobalCalls::findFunction($test->call, ['strlen' => true, 'mb_strlen' => true], $context)) === null
			|| $context->getAnalysis(Types::class)->isOfType($test->value, 'string') !== Tristate::Yes
		) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertainty($test->call, $context);
		$fixable = !$node->getFirstToken()->hasCommentUpTo($test->value->getFirstToken())
			&& !$test->value->getLastToken()->hasCommentUpTo($node->getLastToken());
		if (!$context->report(
			$node,
			'The empty string must be tested with ' . ($test->empty ? "`=== ''`" : "`!== ''`") . ", not through `$function()`.",
			fixable: $fixable,
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			return;
		}

		// an equality binds more loosely than the comparison or the negation it takes the place of
		$node->replaceWithExpression((new Builder)->binary($test->value, $test->empty ? '===' : '!==', ''));
	}
}
