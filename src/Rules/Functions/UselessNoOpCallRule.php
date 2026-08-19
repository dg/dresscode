<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;


/**
 * PHP 8.0 turned the resources of curl, GD and XML into objects, and PHP 8.1 those of fileinfo; it frees them
 * by itself, and the functions that freed them do nothing on those versions. PHP 8.5 deprecated them outright.
 * The call goes, with the statement it makes.
 *
 * Only a call standing as a statement is removed, and only one whose arguments would do nothing when they
 * ran: a call whose value something takes is left alone, being a question about the code rather than
 * a freeing, and so is a call where the target version still frees the resource with it.
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
	/** function → the version since which it frees nothing */
	private const Functions = [
		'curl_close' => '8.0',
		'curl_share_close' => '8.0',
		'finfo_close' => '8.1',
		'imagedestroy' => '8.0',
		'xml_parser_free' => '8.0',
	];


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
		foreach (self::Functions as $function => $version) {
			if (
				$resolver->isGlobalFunctionCall($call, $function)
				&& version_compare($context->getPhpVersion(), $version, '>=')
				&& $context->report($call, "Useless $function() call, PHP frees the object by itself since $version")
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
