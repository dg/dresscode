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
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Parser;
use PhpSyntax\Token;
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
	'dresscode/csv-escape-argument-required',
	Stage::Structure,
	description: 'Writes out the escape argument of the CSV functions, whose default PHP 8.4 deprecated',
	group: Group::Deprecations,
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
			|| $node->arguments->isPartialApplication()
			|| version_compare($context->getPhpVersion(), '8.4', '<')
			|| $node->hasComment()
		) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		foreach (self::Functions as $function => $position) {
			if (
				!$resolver->isGlobalFunctionCall($node, $function)
				|| $node->arguments->findArgument('escape', $position) !== null
				|| count($node->arguments->items) === 0
				|| !$context->report($node, "The escape argument of $function() must be written out, its default being deprecated")
			) {
				continue;
			}

			$template = (new Parser)->parseExpression("f(escape: '\\\\')");
			assert($template instanceof Expression\FunctionCallNode);
			$argument = $template->arguments->items->getItems()[0];
			assert($argument instanceof ArgumentNode);
			$template->arguments->items->removeItem($argument);
			$node->arguments->items->append($argument);
			return;
		}
	}
}
