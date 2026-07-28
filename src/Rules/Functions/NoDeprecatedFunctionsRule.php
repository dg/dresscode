<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\PhpSymbols;
use DressCode\{Config, NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;


/**
 * Calls of internal functions deprecated in the version of PHP the code targets: `strftime()` is reported from
 * PHP 8.1 on, and not in a project that targets PHP 8.0.
 */
#[RuleInfo(
	'dresscode/noDeprecatedFunctions',
	Stage::Structure,
	description: 'Reports calls of internal functions deprecated in the targeted version of PHP',
	group: RuleGroup::Deprecations,
)]
final class NoDeprecatedFunctionsRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode || !$node->name instanceof NameNode) {
			return;
		}

		$symbols = $context->getAnalysis(PhpSymbols::class);
		if (
			($symbols->findDeprecatedSince($node->name->shortName) === null && !NodeHelpers::importsFunctionAs($context))
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node)
		) {
			return;
		}

		// the function the name reaches, which an import may call by another name
		$name = strtolower($context->getAnalysis(NameResolver::class)->resolveFunction($node->name));
		$since = $symbols->findDeprecatedSince($name);
		if ($since !== null && version_compare($context->phpVersion, $since, '>=')) {
			// the oldest version DressCode targets stands for every version before it, so it names no version
			$context->report(
				$node->name,
				"Function `$name()` is deprecated" . ($since === Config::MinPhpVersion ? '' : " since PHP $since"),
				fixable: false,
			);
		}
	}
}
