<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Token;


/**
 * PHP 8.0 turned the resources of curl, fileinfo, GD and XML into objects, which it frees by itself, and the
 * functions that used to free them have done nothing since; PHP 8.5 deprecated them outright. The call goes,
 * with the statement it makes.
 *
 * Only a call standing as a statement is removed, and only one whose arguments would do nothing when they
 * ran: a call whose value something takes is left alone, being a question about the code rather than
 * a freeing, and so is anything in a version older than 8.0, where the call still freed the resource.
 */
#[RuleInfo(
	'dresscode/useless-no-op-call',
	Stage::Structure,
	description: 'Removes calls of the functions that have freed nothing since PHP 8.0',
	group: Group::Cleanup,
	requires: ['php' => '>=8.0'],
)]
final class UselessNoOpCallRule extends NodeRule
{
	private const Functions = ['curl_close', 'curl_share_close', 'finfo_close', 'imagedestroy', 'xml_parser_free'];


	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$call = $node instanceof ExpressionStatementNode ? $node->expression : null;
		if (
			!$call instanceof FunctionCallNode
			|| !$call->name instanceof NameNode
			|| $node->hasComment()
			|| !self::hasPlainArguments($call)
		) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		foreach (self::Functions as $function) {
			if (
				$resolver->isGlobalFunctionCall($call, $function)
				&& $context->report($call, "Useless $function() call, PHP 8.0 frees what it freed by itself")
			) {
				$node->remove();
				return;
			}
		}
	}


	/** Whether every argument is written out and reading it again would do nothing, so that dropping it is free. */
	private static function hasPlainArguments(FunctionCallNode $call): bool
	{
		foreach ($call->arguments->items as $argument) {
			if (
				!$argument instanceof ArgumentNode
				|| $argument->ampersand
				|| $argument->ellipsis
				|| !$argument->value->isRepeatableRead()
			) {
				return false;
			}
		}

		return true;
	}
}
