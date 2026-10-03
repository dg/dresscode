<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\{Gap, RuleContext};
use DressCode\Rules\Whitespace\IndentationRule;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, SymbolKind, Token, Trivia};
use PhpSyntax\Nodes\{ElseifNode, Expression, ExpressionNode, PlainNodeList, Scalar, SeparatedNodeList, Statement, StatementNode};
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use function array_slice, assert, count;


/**
 * Queries and constructions over the tree that rules share.
 * @internal
 */
final class NodeHelpers
{
	/**
	 * The `if`, `elseif`, `while` or `do-while` whose condition the operation is a part of, reached from the condition down
	 * through logical operators alone, not through parentheses or a negation; null for any other operation.
	 */
	public static function findConditionStatement(
		Expression\BinaryOpNode $operation,
	): Statement\IfNode|ElseifNode|Statement\WhileNode|Statement\DoWhileNode|null
	{
		for ($node = $operation; $node instanceof BinaryOpNode && $node->isLogical(); $node = $parent) {
			$parent = $node->parent;
			if (
				$parent instanceof Statement\IfNode
				|| $parent instanceof ElseifNode
				|| $parent instanceof Statement\WhileNode
				|| $parent instanceof Statement\DoWhileNode
			) {
				return $parent->condition === $node ? $parent : null;
			}
		}

		return null;
	}


	/**
	 * Whether the lines of the tokens have the indentation dresscode/indentation gives them, or nothing places them:
	 * a decision by the width of a line waits for it, and a pass later takes it over the right indentation.
	 */
	public static function isLineInPlace(Gap $gap, Token ...$tokens): bool
	{
		$indentation = $gap->findRule(IndentationRule::class);
		return $indentation === null || array_all($tokens, fn(Token $token) => $indentation->isLineInPlace($token, $gap->style));
	}


	/**
	 * The negation of the expression as a new detached node with empty trivia on its edges: an equality flips
	 * its operator, `!` is dropped, true and false swap, what binds tightly enough gets `!`, anything else `!(...)`.
	 * An ordering is not flipped, because against NAN both `<` and `>=` are false.
	 */
	public static function negate(ExpressionNode $expression): ExpressionNode
	{
		$copy = $expression->withoutEdgeTrivia();
		if ($copy instanceof Expression\BinaryOpNode && ($operator = self::negateComparison($copy->operator))) {
			$copy->operator = $operator;
			return $copy;
		} elseif ($copy instanceof Expression\UnaryOpNode && $copy->operator->is('!')) {
			$inner = $copy->expression instanceof Expression\ParenthesizedNode ? $copy->expression->expression : $copy->expression;
			return $inner->withoutEdgeTrivia();
		} elseif ($copy instanceof Scalar\BooleanNode) {
			return (new Builder)->expression($copy->value ? 'false' : 'true');
		}

		$negation = (new Builder)->expression('!0');
		assert($negation instanceof Expression\UnaryOpNode);
		$negation->expression->replaceWithExpression($copy);
		return $negation;
	}


	/** The operator of the opposite equality with the trivia of the given one, null for other operators. */
	private static function negateComparison(Token $operator): ?Token
	{
		[$kind, $text] = match (true) {
			$operator->is(Token::IsEqual) => [Token::IsNotEqual, '!='],
			$operator->is(Token::IsNotEqual) => [Token::IsEqual, '=='],
			$operator->is(Token::IsIdentical) => [Token::IsNotIdentical, '!=='],
			$operator->is(Token::IsNotIdentical) => [Token::IsIdentical, '==='],
			default => [null, null],
		};
		if ($kind === null || $text === null) {
			return null;
		}

		return new Token($kind, $text)
			->setLeadingTrivia($operator->leadingTrivia)
			->setTrailingTrivia($operator->trailingTrivia);
	}


	/**
	 * The constructs inside the node that may reach a variable by a name they do not spell out: variable
	 * variables, calls of `compact()`, `extract()` and `get_defined_vars()`, and `eval` and `include`, whose code runs in
	 * the scope they are written in.
	 * @return list<Node>
	 */
	public static function findDynamicVariableAccesses(Node $node, RuleContext $context): array
	{
		return $node->find(Node::class, fn(Node $inner) => $inner instanceof Expression\IncludeNode
			|| $inner instanceof Expression\EvalNode
			|| ($inner instanceof Expression\VariableNode && ($inner->dollar !== null || !$inner->name instanceof Token))
			|| (
				$inner instanceof Expression\FunctionCallNode
				&& GlobalCalls::findFunction($inner, ['compact' => true, 'extract' => true, 'get_defined_vars' => true], $context) !== null
			));
	}


	/** Whether the file imports a function under a name other than its own, so that a call names another function than it spells. */
	public static function importsFunctionAs(RuleContext $context): bool
	{
		/** @var \WeakMap<NameResolver, bool> $known  by the resolver, which the first mutation replaces */
		static $known = new \WeakMap;
		$resolver = $context->getAnalysis(NameResolver::class);
		if (isset($known[$resolver])) {
			return $known[$resolver];
		}

		$file = $context->file;
		$scopes = [$file, ...array_filter($file->statements->getItems(), fn($statement) => $statement instanceof Statement\NamespaceNode)];
		return $known[$resolver] = array_any($scopes, fn(Node $scope) => array_any(
			$resolver->getImports(SymbolKind::Function, $scope),
			fn(string $function, string $alias) => strcasecmp($alias, substr((string) strrchr('\\' . $function, '\\'), 1)) !== 0,
		));
	}


	/**
	 * Writes the items of a group use as imports of their own, `use A\{B, C as D};` becoming `use A\B;` and
	 * `use A\C as D;`, each on a line of its own with the indentation of the group.
	 * @param PlainNodeList<StatementNode> $list
	 */
	public static function expandGroup(Statement\UseNode $node, PlainNodeList $list, string $lineEnding): void
	{
		$builder = new Builder;
		$statements = [];
		foreach ($node->items->getItems() as $item) {
			$type = match ($item->symbolKind) {
				SymbolKind::Function => 'function ',
				SymbolKind::Constant => 'const ',
				SymbolKind::ClassLike => '',
			};
			$alias = $item->alias === null ? '' : ' as ' . $item->alias->text;
			$statements[] = $builder->statement("use $type{$item->fullName}$alias;");
		}

		$indentation = $node->getFirstToken()->getIndentation();
		$last = array_pop($statements);
		if ($last === null) {
			return;
		}

		$index = $list->indexOf($node);
		$node->replaceWith($last);
		foreach ($statements as $i => $statement) {
			$leading = $i === 0 ? $last->getFirstToken()->leadingTrivia : [new Trivia(Trivia::Whitespace, $indentation)];
			$statement->setEdgeTrivia($leading, [new Trivia(Trivia::LineEnding, $lineEnding)]);
			$list->insert($index + $i, $statement);
		}

		if (count($statements)) {
			$last->setEdgeTrivia(leading: [new Trivia(Trivia::Whitespace, $indentation)]);
		}
	}


	/**
	 * A comment standing inside the node or at the end of its last line, which `Node::hasComment()` does not count;
	 * true for a node without tokens, which nothing can be said of.
	 */
	public static function hasCommentUpToLineEnding(Node $node): bool
	{
		$first = $node->getFirstToken();
		$last = $node->getLastToken();
		return $first === null || $last === null || $first->hasCommentUpTo($last) || $last->hasComment();
	}


	/**
	 * The width the node takes on one line, the gaps between its tokens counted as a single space each; null for
	 * a node a comment stands in, whose line no measure can tell.
	 */
	public static function measureNode(Node $node): ?int
	{
		$last = $node->getLastToken();
		$width = 0;
		for ($token = $node->getFirstToken(); $token !== null; $token = $token->getNext()) {
			if ($token->hasComment()) {
				return null;
			}

			$width += mb_strlen($token->text);
			if ($token === $last) {
				return $width;
			}

			$width += $token->getTrailingSpace() === '' ? 0 : 1;
		}

		return null;
	}


	/**
	 * Splits a declaration listing several items (`const A = 1, B = 2;`, `public $a, $b;`, `use A, B;`) into
	 * one declaration per item: every item after the first gets a copy of the declaration of its own, the copies
	 * follow the original in its list and the original keeps the first item. The slot names the list of items
	 * of the declaration (`'items'`, `'traits'`).
	 * @template T of Node
	 * @param  T  $node
	 * @param  PlainNodeList<T>  $list
	 */
	public static function splitItems(Node $node, PlainNodeList $list, string $slot, string $lineEnding): void
	{
		$items = $node->$slot;
		assert($items instanceof SeparatedNodeList);
		$members = $items->getItems();
		$index = $list->indexOf($node);
		$indentation = $node->getFirstToken()?->getIndentation() ?? '';
		$trailing = $node->getLastToken()->trailingTrivia ?? [];
		$end = new Trivia(Trivia::LineEnding, $lineEnding);
		foreach (array_slice($members, 1) as $i => $member) {
			$copy = clone $node;
			$copied = $copy->$slot;
			assert($copied instanceof SeparatedNodeList);
			foreach ($copied->getItems() as $j => $item) {
				if ($j !== $i + 1) {
					$copied->removeItem($item);
				}
			}

			// the item kept the indentation of the line it continued, which now stands after the keyword
			$first = $copied->getItems()[0]->getFirstToken();
			$first?->setLeadingTrivia(array_values(array_filter($first->leadingTrivia, fn(Trivia $trivia) => $trivia->isComment())));

			$copy->setEdgeTrivia([new Trivia(Trivia::Whitespace, $indentation)], $i === count($members) - 2 ? $trailing : [$end]);
			$list->insert($index + $i + 1, $copy);
		}

		foreach (array_slice($members, 1) as $member) {
			$items->removeItem($member);
		}

		$node->setEdgeTrivia(trailing: [$end]);
	}
}
