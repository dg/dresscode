<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, Expression};
use function count;


/**
 * PHP 8.4 deprecated leaving the `escape` of the CSV functions to its default, because that default is
 * a backslash and CSV has no escape character; a future version will make it empty. The rule writes the
 * default out, so that the code goes on doing what it does today and says so.
 *
 * The argument is written by name, which needs no other argument filled in, and the rule runs where the
 * code targets 8.4 or later. The methods of `SplFileObject` are left alone: which object a call stands on
 * the code does not say.
 */
#[RuleInfo(
	'dresscode/csvEscapeArgumentRequired',
	Stage::Structure,
	description: 'Writes out the `escape` argument of the CSV functions, whose default PHP 8.4 deprecated',
	group: RuleGroup::Deprecations,
)]
final class CsvEscapeArgumentRequiredRule extends NodeRule
{
	/** function => the position the escape parameter stands at */
	private const Functions = ['fputcsv' => 4, 'fgetcsv' => 4, 'str_getcsv' => 3];


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\FunctionCallNode
			|| ($function = GlobalCalls::findFunction($node, self::Functions, $context)) === null
			|| $node->arguments->isPartialApplication()
			|| version_compare($context->phpVersion, '8.4', '<')
			|| $node->hasInnerComment()
			|| $node->arguments->findArgument('escape', self::Functions[$function]) !== null
			|| count($node->arguments->items) === 0
			|| !$context->report($node, "The `escape` argument of `$function()` must be written out, its default being deprecated")
		) {
			return;
		}

		$node->arguments->items->append((new Builder)->fragment(ArgumentNode::class, "escape: '\\\\'"));
	}
}
