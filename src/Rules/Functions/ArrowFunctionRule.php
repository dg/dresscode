<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode};
use PhpSyntax\Nodes\Statement\ReturnNode;
use function count;


/**
 * A closure whose body is a single `return` becomes an arrow function; variables captured with `use` come
 * along automatically, one captured by reference does not, so such a closure stays. So does a closure whose
 * body reaches a variable by a name it does not spell out (`$$name`, `compact()`, `extract()`,
 * `get_defined_vars()`, `eval`, `include`), because an arrow function captures only the variables its
 * expression names. A comment anywhere after the parameters keeps the closure as well.
 */
#[RuleInfo(
	'dresscode/arrow-function',
	Stage::Structure,
	description: 'Replaces a closure returning a single expression with an arrow function',
	group: Group::Modernization,
)]
final class ArrowFunctionRule extends NodeRule implements ConfigurableRule
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

		$stmts = $node->body->statements->getItems();
		$return = $stmts[0] ?? null;
		if (
			count($stmts) !== 1
			|| !$return instanceof ReturnNode
			|| $return->expression === null
			|| $node->closeParen->hasCommentUpTo($node->body->closeBrace)
			|| (!$this->allowNested && ($node->body->find(ClosureNode::class) || $node->body->find(ArrowFunctionNode::class)))
			|| NodeHelpers::findDynamicVariableAccesses($node->body, $context) !== []
		) {
			return;
		}

		foreach ($node->uses?->variables->getItems() ?? [] as $use) {
			if ($use->ampersand !== null) {
				return;
			}
		}

		if (!$context->report($node->functionKeyword, 'A closure returning a single expression must be an arrow function')) {
			return;
		}

		$fn = (new Parser)->parseExpression(
			($node->staticKeyword ? 'static ' : '')
			. 'fn' . ($node->ampersand ? '&' : '') . '()'
			. ($node->returnType ? ': ' . trim((string) $node->returnType) : '')
			. ' => 0',
		);
		assert($fn instanceof ArrowFunctionNode);
		$fn->attributes = clone $node->attributes;
		$fn->parameters = clone $node->parameters;

		$expr = $return->expression->withoutEdgeTrivia();
		$fn->expression->replaceWith($expr);
		$node->replaceWith($fn);
	}
}
