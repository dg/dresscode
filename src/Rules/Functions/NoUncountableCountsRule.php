<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;


/**
 * `count()` of what is neither an array nor `Countable`, which PHP 8.0 made a `TypeError` where it used to give 1 or 0
 * with a warning. Only a value the types tell is no such thing is reported, what the code meant by it being for the
 * project to say; there is no fix.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, NameResolver::class])]
final class NoUncountableCountsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'mistakes.countOfUncountable',
			Domain::state('forbidden'),
			'`count($value)` of what is neither an array nor `Countable`, a `TypeError` since PHP 8.0, which is only reported',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$value = $node instanceof FunctionCallNode
			&& !$node->arguments->isPartialApplication()
			&& $resolver->findGlobalFunction($node, ['count', 'sizeof']) !== null
			? $node->arguments->findArgument('value', 0)?->value
			: null;
		if ($value !== null && $context->getAnalysis(Types::class)->isOfType($value, 'array|Countable') === Tristate::No) {
			$context->report(
				$value,
				'The value is neither an array nor `Countable`, which `count()` refuses since PHP 8.0.',
				fixable: false,
			);
		}
	}
}
