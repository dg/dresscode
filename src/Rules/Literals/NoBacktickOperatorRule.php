<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Parser, SymbolKind, Token};
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringPartNode;


/**
 * `shell_exec("...")` instead of the backticks PHP 8.5 deprecated; a command containing a quote or a backtick
 * stays, because its escaping would have to change. The backticks always run the global function, so the call
 * is written the shortest way that reaches it for certain: fully qualified where an import takes the name, or
 * in a namespace whose name resolution is uncertain.
 */
#[RuleInfo(
	'dresscode/no-backtick-operator',
	Stage::Structure,
	description: 'Runs a command through shell_exec() instead of backticks',
	group: Group::Deprecations,
)]
final class NoBacktickOperatorRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ShellExecNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ShellExecNode) {
			return;
		}

		$command = '';
		foreach ($node->parts->getItems() as $part) {
			if ($part instanceof InterpolatedStringPartNode && preg_match('~[`"\']~', $part->token->text)) {
				return;
			}

			$command .= $part;
		}

		if (!$context->report($node, 'The backtick operator must be written as a shell_exec() call')) {
			return;
		}

		$function = $context->getAnalysis(NameResolver::class)->getShortName('shell_exec', SymbolKind::Function, $node);
		$node->replaceWith((new Parser)->parseExpression($function . '("' . $command . '")'));
	}
}
