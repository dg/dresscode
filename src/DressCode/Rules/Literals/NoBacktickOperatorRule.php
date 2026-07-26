<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringPartNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;


/**
 * `shell_exec("...")` instead of backticks; a command containing a quote or a backtick stays, because its
 * escaping would have to change.
 */
#[RuleInfo(
	'dresscode/no-backtick-operator',
	Stage::Structure,
	description: 'Runs a command through shell_exec() instead of backticks',
)]
final class NoBacktickOperatorRule extends Rule
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
			if ($part instanceof InterpolatedStringPartNode && preg_match('~[`"\']~', $part->text->text)) {
				return;
			}

			$command .= $part;
		}

		if ($context->report($node, 'The backtick operator must be written as a shell_exec() call')) {
			$node->replaceWith((new Parser)->parseExpression('shell_exec("' . $command . '")'));
		}
	}
}
