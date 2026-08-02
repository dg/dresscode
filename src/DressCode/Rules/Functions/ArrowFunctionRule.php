<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Statement\ReturnNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use function count;


/**
 * A closure whose body is a single `return` becomes an arrow function; variables captured with `use` come
 * along automatically, one captured by reference does not, so such a closure stays. A comment anywhere
 * after the parameters keeps the closure as well.
 */
#[RuleInfo(
	'dresscode/arrow-function',
	Stage::Structure,
	description: 'Replaces a closure returning a single expression with an arrow function',
)]
final class ArrowFunctionRule extends Rule implements ConfigurableRule
{
	private bool $allowNested = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'allowNested' => Expect::bool(true)->description('A closure containing another closure or arrow function is converted too'),
		]);
	}


	public function configure(array $options): void
	{
		$this->allowNested = $options['allowNested'];
	}


	public function getVisitedTypes(): array
	{
		return [ClosureNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClosureNode) {
			return;
		}

		$stmts = $node->body->stmts->getItems();
		$return = $stmts[0] ?? null;
		if (
			count($stmts) !== 1
			|| !$return instanceof ReturnNode
			|| $return->expr === null
			|| $node->closeParen->hasCommentUpTo($node->body->closeBrace)
			|| (!$this->allowNested && ($node->body->getDescendants(ClosureNode::class) || $node->body->getDescendants(ArrowFunctionNode::class)))
		) {
			return;
		}

		foreach ($node->uses?->vars->getItems() ?? [] as $use) {
			if ($use->byRef !== null) {
				return;
			}
		}

		if (!$context->report($node->functionKeyword, 'A closure returning a single expression must be an arrow function')) {
			return;
		}

		$fn = (new Parser)->parseExpression(
			($node->staticKeyword ? 'static ' : '')
			. 'fn' . ($node->byRef ? '&' : '') . '()'
			. ($node->returnType ? ': ' . trim((string) $node->returnType) : '')
			. ' => 0',
		);
		assert($fn instanceof ArrowFunctionNode);
		$fn->setAttributes(clone $node->attributes);
		$fn->setParams(clone $node->params);

		$expr = clone $return->expr;
		$expr->getFirstToken()?->setLeadingTrivia([]);
		$expr->getLastToken()?->setTrailingTrivia([]);
		$fn->expr->replaceWith($expr);
		$node->replaceWith($fn);
	}
}
