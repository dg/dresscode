<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, SymbolKind, Token};
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringPartNode;


/**
 * `shell_exec("...")` instead of the backticks PHP 8.5 deprecated; a command containing a quote or a backtick
 * is reported and stays as written. The backticks always run the global function, so the call is written the
 * shortest way that reaches it for certain: fully qualified where an import takes the name, or in a namespace whose name resolution is
 * uncertain.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoBacktickOperatorsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.backticks', Domain::state('forbidden'), '`shell_exec()` for the backtick operator')];
	}


	public function getVisitedNodes(): array
	{
		return [ShellExecNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ShellExecNode) {
			return;
		}

		$command = '';
		$quoted = false;
		foreach ($node->parts->getItems() as $part) {
			$quoted = $quoted || ($part instanceof InterpolatedStringPartNode && preg_match('~[`"\']~', $part->token->text));
			$command .= $part;
		}

		$message = 'The backtick operator must be written as a `shell_exec()` call';
		if ($quoted) {
			$context->report($node, $message . ', but the command contains a quote or a backtick.', fixable: false);
			return;
		}

		if (!$context->report($node, $message . '.')) {
			return;
		}

		$function = $context->getAnalysis(NameResolver::class)->shortenName('shell_exec', SymbolKind::Function, $node);
		$node->replaceWith((new Builder)->expression($function . '("' . $command . '")'));
	}
}
