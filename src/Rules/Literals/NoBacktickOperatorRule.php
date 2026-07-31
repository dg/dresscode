<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringPartNode;
use PhpSyntax\Parser;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;


/**
 * `shell_exec("...")` instead of backticks; a command containing a quote or a backtick stays, because its
 * escaping would have to change. The backticks always run the global function, so the call is written the
 * shortest way that reaches it for certain: fully qualified where an import takes the name, or in a namespace
 * whose name resolution is uncertain.
 */
#[RuleInfo(
	'dresscode/no-backtick-operator',
	Stage::Structure,
	description: 'Runs a command through shell_exec() instead of backticks',
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
