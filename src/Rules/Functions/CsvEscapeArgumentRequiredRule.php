<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, Expression};
use function count;


/**
 * PHP 8.4 deprecated leaving the `escape` of the CSV functions to its default, because that default is
 * a backslash and CSV has no escape character; a future version will make it empty. The rule writes the
 * default out, so that the code goes on doing what it does today and says so.
 *
 * The argument is written by name, which needs no other argument filled in. The methods of `SplFileObject`
 * are left alone: which object a call stands on the code does not say; and so is a call unpacking an
 * argument, which may hold the `escape` already.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class CsvEscapeArgumentRequiredRule extends NodeRule
{
	/** function => the position the escape parameter stands at */
	private const Functions = ['fputcsv' => 4, 'fgetcsv' => 4, 'str_getcsv' => 3];


	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.csvEscapeArgument', Domain::state('required'), 'The `escape` argument of `fgetcsv()` and kin written out, since PHP 8.4 deprecated relying on its default')];
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\FunctionCallNode
			|| ($function = GlobalCalls::findFunction($node, self::Functions, $context)) === null
			|| $node->arguments->isPartialApplication()
			|| $node->hasInnerComment()
			|| $node->arguments->findArgument('escape', self::Functions[$function]) !== null
			|| count($node->arguments->items) === 0
			|| array_any($node->arguments->items->getItems(), fn(Node $argument) => $argument instanceof ArgumentNode && $argument->ellipsis !== null)
		) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertainty($node, $context);
		if (!$context->report(
			$node,
			"The `escape` argument of `$function()` must be written out, because its default is deprecated.",
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			return;
		}

		$node->arguments->items->append((new Builder)->fragment(ArgumentNode::class, "escape: '\\\\'"));
	}
}
