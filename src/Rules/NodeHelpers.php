<?php declare(strict_types=1);

namespace DressCode\Rules;

use DressCode\Analyses;
use DressCode\RuleContext;
use DressCode\Rules\Namespaces\ImportNotationRule;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\AttributeGroupNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Scalar;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\Parser;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use PhpSyntax\UnqualifiedResolution;
use function array_slice, assert, count;


/**
 * Queries and constructions over the tree that several rules share.
 * @internal
 */
final class NodeHelpers
{
	private const BooleanOperators = [
		TokenKind::IsEqual, TokenKind::IsNotEqual, TokenKind::IsIdentical, TokenKind::IsNotIdentical,
		'<', '>', TokenKind::IsSmallerOrEqual, TokenKind::IsGreaterOrEqual,
		TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];


	/**
	 * Whether the declaration says of itself that it is deprecated, by the `@deprecated` annotation or by the
	 * `#[\Deprecated]` attribute of PHP 8.4. What is deprecated cannot be renamed any more, so a rule about
	 * names leaves it alone.
	 */
	public static function isDeprecated(Node $node, RuleContext $context): bool
	{
		$docComment = $node->getDocComment();
		if ($docComment !== null && !$docComment->inInterpolation) {
			foreach ($context->getAnalysis(Analyses\PhpDoc::class)->parse($docComment)->children as $child) {
				if ($child instanceof PhpDocTagNode && strcasecmp($child->name, '@deprecated') === 0) {
					return true;
				}
			}
		}

		$attributes = property_exists($node, 'attributes') ? $node->attributes : null;
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($attributes instanceof NodeList ? $attributes->getItems() : [] as $group) {
			foreach ($group instanceof AttributeGroupNode ? $group->attributes->getItems() : [] as $attribute) {
				if (strcasecmp($resolver->resolveClass($attribute->name, $node), 'Deprecated') === 0) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Whether the expression yields a boolean whatever its operands: a comparison, a logical operation,
	 * a negation, instanceof, isset(), empty(), a bool cast or a boolean literal.
	 */
	public static function isBoolean(ExpressionNode $expression): bool
	{
		return match (true) {
			$expression instanceof Expression\BinaryOpNode => $expression->operator->is(...self::BooleanOperators),
			$expression instanceof Expression\UnaryOpNode => $expression->operator->is('!'),
			$expression instanceof Expression\CastNode => $expression->cast->kind === TokenKind::BoolCast,
			$expression instanceof Expression\ParenthesizedNode => self::isBoolean($expression->expression),
			$expression instanceof Scalar\BooleanNode => true,
			default => $expression instanceof Expression\InstanceofNode || $expression instanceof Expression\IssetNode || $expression instanceof Expression\EmptyNode,
		};
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
			return (new Parser)->parseExpression($copy->value ? 'false' : 'true');
		}

		$negation = (new Parser)->parseExpression('!0');
		assert($negation instanceof Expression\UnaryOpNode);
		$negation->expression->replaceWithExpression($copy);
		return $negation;
	}


	/** The operator of the opposite equality with the trivia of the given one, null for other operators. */
	private static function negateComparison(Token $operator): ?Token
	{
		[$kind, $text] = match (true) {
			$operator->is(TokenKind::IsEqual) => [TokenKind::IsNotEqual, '!='],
			$operator->is(TokenKind::IsNotEqual) => [TokenKind::IsEqual, '=='],
			$operator->is(TokenKind::IsIdentical) => [TokenKind::IsNotIdentical, '!=='],
			$operator->is(TokenKind::IsNotIdentical) => [TokenKind::IsIdentical, '==='],
			default => [null, null],
		};
		if ($kind === null || $text === null) {
			return null;
		}

		$new = new Token($kind, $text);
		$new->setLeadingTrivia($operator->leadingTrivia);
		$new->setTrailingTrivia($operator->trailingTrivia);
		return $new;
	}


	/**
	 * Whether the block ends with a statement after which the code does not go on: return, break, continue,
	 * goto, throw or exit.
	 */
	public static function endsWithExit(Statement\BlockNode $block): bool
	{
		$stmts = $block->statements->getItems();
		$last = $stmts === [] ? null : $stmts[count($stmts) - 1];
		return match (true) {
			$last instanceof Statement\ReturnNode,
			$last instanceof Statement\BreakNode,
			$last instanceof Statement\ContinueNode,
			$last instanceof Statement\GotoNode => true,
			$last instanceof Statement\ExpressionStatementNode => $last->expression instanceof Expression\ThrowNode || $last->expression instanceof Expression\ExitNode,
			default => false,
		};
	}


	/**
	 * The constructs inside the node that may reach a variable by a name they do not spell out: variable
	 * variables, calls of compact(), extract() and get_defined_vars(), and eval and include, whose code runs in
	 * the scope they are written in.
	 * @return list<Node>
	 */
	public static function findDynamicVariableAccesses(Node $node, RuleContext $context): array
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $node->find(Node::class, fn(Node $inner) => $inner instanceof Expression\IncludeNode
			|| $inner instanceof Expression\EvalNode
			|| ($inner instanceof Expression\VariableNode && ($inner->dollar !== null || !$inner->name instanceof Token))
			|| (
				$inner instanceof Expression\FunctionCallNode
				&& array_any(['compact', 'extract', 'get_defined_vars'], fn(string $function) => $resolver->isGlobalFunctionCall($inner, $function))
			));
	}


	/**
	 * Why a call taken as a call of a global function may call another one: its name is unqualified in a namespace
	 * that may declare a function of that name elsewhere (`UnqualifiedResolution::Uncertain`). A rule rewriting such a call
	 * reports it as risky with this reason after the message; null when the call is certain.
	 */
	public static function findUncertainty(Expression\FunctionCallNode $call, RuleContext $context): ?string
	{
		$name = $call->name;
		return $name instanceof NameNode
			&& $name->isUnqualified()
			&& $context->getAnalysis(NameResolver::class)->getUnqualifiedResolution($name->text, SymbolKind::Function, $call) === UnqualifiedResolution::Uncertain
			? ', unless the namespace declares ' . strtolower($name->text) . '()'
			: null;
	}


	/**
	 * How the name of another global function is written in place of the name of a call of a global one: bare where
	 * the replaced name is bare, nothing takes the bare name and it is no less certain than the replaced one, which is
	 * the fallback the call already stood on; else with the leading backslash.
	 */
	public static function spellGlobalFunction(string $function, NameNode $replaced, RuleContext $context): string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $replaced->isUnqualified()
			&& $resolver->isAliasFree($function, SymbolKind::Function, $replaced)
			&& (
				$resolver->getUnqualifiedResolution($replaced->text, SymbolKind::Function, $replaced) === UnqualifiedResolution::Uncertain
				|| $resolver->getUnqualifiedResolution($function, SymbolKind::Function, $replaced) === UnqualifiedResolution::Global
			)
			? $function
			: '\\' . $function;
	}


	/**
	 * Whether PHP optimizes the call of the global function with its arguments: most of the functions it optimizes with
	 * any, some only with every argument constant, sprintf() only with a constant format of %s and %d alone, in_array()
	 * only with a constant array it looks up in a hash and array_slice() only of func_get_args(); none with a named
	 * argument, and none with an unpacked one unless $unpacked asks about the call the unpacked last argument would make
	 * if it passed its values one by one, none of them constant.
	 */
	public static function isOptimizedCall(
		Expression\FunctionCallNode $call,
		string $function,
		RuleContext $context,
		bool $unpacked = false,
	): bool
	{
		$values = [];
		$rest = false;
		foreach ($call->arguments->items->getItems() as $argument) {
			if (
				!$argument instanceof ArgumentNode
				|| $argument->name !== null
				|| $rest
				|| ($argument->ellipsis !== null && !$unpacked)
			) {
				return false;
			} elseif ($argument->ellipsis !== null) {
				$rest = true;
			} else {
				$values[] = $argument->value;
			}
		}

		// compact(), extract(), get_defined_vars() and func_get_arg() are only detected by the optimizer, not replaced,
		// and assert() is compiled specially whether its name is known to be global or not
		return match (strtolower($function)) {
			'array_key_exists', 'boolval', 'call_user_func', 'call_user_func_array', 'count', 'doubleval', 'floatval', 'func_get_args',
			'func_num_args', 'get_called_class', 'get_class', 'gettype', 'intval', 'is_array', 'is_bool', 'is_double', 'is_float',
			'is_int', 'is_integer', 'is_long', 'is_null', 'is_object', 'is_resource', 'is_scalar', 'is_string', 'sizeof', 'strlen',
			'strval' => true,
			'chr', 'constant', 'define', 'defined', 'dirname', 'extension_loaded', 'function_exists', 'ini_get', 'is_callable', 'ord'
				=> $values !== [] && array_all($values, fn(Node $value) => self::isConstantExpression($value, $context)),
			'sprintf' => self::isConcatenatedFormat($values, $rest),
			'in_array' => self::isLookupArray($values, $context),
			'array_slice' => count($values) === 2
				&& $values[0] instanceof Expression\FunctionCallNode
				&& $values[0]->arguments->items->getItems() === []
				&& $context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($values[0], 'func_get_args')
				&& $values[1] instanceof Scalar\IntegerNode,
			default => false,
		};
	}


	/**
	 * Whether sprintf() with the arguments is compiled into a concatenation: the format is a string known while compiling
	 * with no placeholder but %s and %d, one for each value, or for each value and some an unpacked argument passes.
	 * @param  list<Node>  $values
	 */
	private static function isConcatenatedFormat(array $values, bool $rest): bool
	{
		$format = self::findStringValue($values[0] ?? null);
		if ($format === null) {
			return false;
		}

		$format = str_replace('%%', '', $format);
		$placeholders = preg_match_all('~%[sd]~', $format);
		return !preg_match('~%(?![sd])~', $format)
			&& ($rest ? $placeholders >= count($values) - 1 : $placeholders === count($values) - 1);
	}


	/**
	 * Whether in_array() with the arguments looks the needle up in a hash built while compiling: the haystack is an array
	 * of strings and integers with constant keys, the strict flag is constant if given, and without it every item is
	 * a string that is not numeric.
	 * @param  list<Node>  $values
	 */
	private static function isLookupArray(array $values, RuleContext $context): bool
	{
		$haystack = $values[1] ?? null;
		$flag = $values[2] ?? null;
		if (
			!$haystack instanceof Expression\ArrayNode
			|| count($values) > 3
			|| ($flag !== null && !self::isConstantExpression($flag, $context))
		) {
			return false;
		}

		$strict = $flag instanceof Scalar\BooleanNode && $flag->value;
		foreach ($haystack->items->getItems() as $item) {
			if (
				!$item instanceof ArrayItemNode
				|| $item->ellipsis !== null
				|| $item->ampersand !== null
				|| ($item->key !== null && !self::isConstantExpression($item->key, $context))
			) {
				return false;
			}

			$value = $item->value;
			$string = self::findStringValue($value);
			$integer = $value instanceof Scalar\IntegerNode
				|| ($value instanceof Expression\UnaryOpNode && $value->operator->is('-', '+') && $value->expression instanceof Scalar\IntegerNode);
			if ($string !== null ? !$strict && is_numeric($string) : !$strict || !$integer) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Whether the value of the expression is known while compiling: a literal, an operation on known values, or a constant
	 * of PHP the compiler reads, which is one written qualified, imported or in the global namespace, and with $unqualified
	 * also one written bare in a namespace, which PHP reaches by the fallback at run time.
	 */
	public static function isConstantExpression(?Node $node, RuleContext $context, bool $unqualified = false): bool
	{
		return match (true) {
			$node instanceof Scalar\StringNode, $node instanceof Scalar\IntegerNode, $node instanceof Scalar\FloatNode,
			$node instanceof Scalar\BooleanNode, $node instanceof Scalar\NullNode, $node instanceof Scalar\MagicConstantNode => true,
			$node instanceof Scalar\HeredocNode => !$node->hasInterpolation(),
			$node instanceof Expression\ConstantFetchNode => self::isReadConstant($node, $context, $unqualified),
			$node instanceof Expression\ParenthesizedNode, $node instanceof Expression\UnaryOpNode, $node instanceof Expression\CastNode
				=> self::isConstantExpression($node->expression, $context, $unqualified),
			$node instanceof Expression\BinaryOpNode => self::isConstantExpression($node->left, $context, $unqualified)
				&& self::isConstantExpression($node->right, $context, $unqualified),
			default => false,
		};
	}


	/** Whether the fetch reads a constant of PHP while compiling, or with $unqualified at least reaches one by the fallback. */
	private static function isReadConstant(
		Expression\ConstantFetchNode $fetch,
		RuleContext $context,
		bool $unqualified,
	): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$name = $fetch->name;
		return $context->getAnalysis(Analyses\PhpSymbols::class)->isInternalConstant($resolver->resolveConstant($name))
			&& (
				$unqualified
				|| !$name->isUnqualified()
				|| $resolver->getNamespace($fetch) === ''
				|| isset($resolver->getConstantImports($fetch)[$name->text])
			);
	}


	/** The value of a string known while compiling, a literal or a concatenation of literals; null for any other expression. */
	private static function findStringValue(?Node $node): ?string
	{
		if ($node instanceof Expression\BinaryOpNode && $node->operator->is('.')) {
			$left = self::findStringValue($node->left);
			$right = self::findStringValue($node->right);
			return $left === null || $right === null ? null : $left . $right;
		}

		return match (true) {
			$node instanceof Scalar\StringNode => $node->value,
			$node instanceof Scalar\HeredocNode && !$node->hasInterpolation() => $node->value,
			$node instanceof Expression\ParenthesizedNode => self::findStringValue($node->expression),
			default => null,
		};
	}


	/**
	 * Whether a use statement can be added to the scope: a file that opens with markup has no line for one unless
	 * an import stands in it already.
	 */
	public static function canAddImport(Statement\NamespaceNode $scope): bool
	{
		$items = $scope->statements->getItems();
		$first = $items[0] ?? null;
		return !$first instanceof Statement\InlineHtmlNode
			|| $first->isPreamble()
			|| array_any($items, fn(Node $stmt) => $stmt instanceof Statement\UseNode);
	}


	/**
	 * Imports the name into the scope in the shape dresscode/import-notation gives its kind, or where that rule gives
	 * none the way the scope writes its imports, so that the rules of their shape and order find nothing to add: where
	 * the order of imports puts it (classes, functions, constants, each alphabetically the way ordered-imports sorts by
	 * default, so other options of it may still find the order wrong), into the statement of its kind standing there
	 * when the shape is combined or that statement lists several names, else with a statement of its own, else first
	 * in the scope behind its declare statements, a blank line apart.
	 */
	public static function addImport(
		Statement\NamespaceNode $scope,
		SymbolKind $kind,
		string $fullName,
		RuleContext $context,
	): void
	{
		$rank = fn(SymbolKind $kind) => match ($kind) {
			SymbolKind::ClassLike => 0,
			SymbolKind::Function => 1,
			SymbolKind::Constant => 2,
		};
		$precedes = fn(string $name) => strcasecmp(strtr($name, ['\\' => ' ']), strtr($fullName, ['\\' => ' '])) < 0;
		$list = $scope->statements;
		$items = $list->getItems();
		// the statement the order of the imports puts the name into: the last one of the kind whose first name precedes it
		$host = null;
		foreach ($items as $stmt) {
			if (
				$stmt instanceof Statement\UseNode
				&& $stmt->kind === $kind
				&& !$stmt->isGroup()
				&& ($host === null || $precedes(trim((string) $stmt->items->getItems()[0])))
			) {
				$host = $stmt;
			}
		}

		$shape = $context->findRule(ImportNotationRule::class)?->getShape($kind);
		if ($host !== null && ($shape === 'combined' || ($shape === null && count($host->items) > 1))) {
			$host->addImport($fullName, index: count(array_filter($host->items->getItems(), fn(UseItemNode $item) => $precedes(trim((string) $item)))));
			return;
		}

		$after = $before = null;
		foreach ($items as $i => $stmt) {
			if (!$stmt instanceof Statement\UseNode) {
				continue;
			} elseif (
				$rank($stmt->kind) < $rank($kind)
				|| ($stmt->kind === $kind && ($stmt->isGroup() || $precedes(trim((string) $stmt->items->getItems()[0]))))
			) {
				$after = $i + 1;
			} else {
				$before ??= $i;
			}
		}

		$keyword = match ($kind) {
			SymbolKind::Function => 'function ',
			SymbolKind::Constant => 'const ',
			SymbolKind::ClassLike => '',
		};
		$statement = (new Parser)->parseStatement("use $keyword$fullName;");
		$eol = new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol);
		$indentOf = fn(?Node $node): array => ($indentation = $node?->getFirstToken()?->getIndentation() ?? '') === ''
			? []
			: [new Trivia(TriviaKind::Whitespace, $indentation)];

		if ($after !== null) {
			$statement->setEdgeTrivia($indentOf($items[$after - 1]), [$eol]);
			$list->insert($after, $statement);
			return;
		}

		if ($before !== null) {
			// the import takes the place of the first one, with what stands above it, and that one keeps its indentation
			$first = $items[$before]->getFirstToken();
			$indent = $indentOf($items[$before]);
			$statement->setEdgeTrivia($first->leadingTrivia ?? [], [$eol]);
			$first?->setLeadingTrivia($indent);
			$list->insert($before, $statement);
			return;
		}

		$index = 0;
		while (($items[$index] ?? null) instanceof Statement\DeclareNode) {
			$index++;
		}

		$neighborFirst = ($items[$index] ?? null)?->getFirstToken();
		$indentation = $neighborFirst?->getIndentation() ?? ($scope->openBrace ? $context->getStyle()->indent : '');
		// a braced namespace ends the line with its brace, an unbraced one is a blank line apart from its statement
		$leading = $scope->openBrace !== null ? [] : [$eol];
		if ($index === 0 && $neighborFirst !== null) { // an open tag stays first
			foreach ($neighborFirst->leadingTrivia as $i => $trivia) {
				if ($trivia->kind === TriviaKind::OpenTag) {
					$leading = [...array_slice($neighborFirst->leadingTrivia, 0, $i + 1), ...$leading];
					$neighborFirst->setLeadingTrivia(array_slice($neighborFirst->leadingTrivia, $i + 1));
					break;
				}
			}
		}

		if ($indentation !== '') {
			$leading[] = new Trivia(TriviaKind::Whitespace, $indentation);
		}

		$statement->setEdgeTrivia($leading, [$eol]);
		$list->insert($index, $statement);
		if ($neighborFirst !== null && ($neighborFirst->leadingTrivia[0] ?? null)?->kind !== TriviaKind::EndOfLine) {
			$neighborFirst->setBlankLinesBefore(1, $context->getStyle()->eol);
		}
	}


	/**
	 * Splits a declaration listing several items (`const A = 1, B = 2;`, `public $a, $b;`, `use A, B;`) into
	 * one declaration per item: every item after the first gets a copy of the declaration of its own, the copies
	 * follow the original in its list and the original keeps the first item. The slot names the list of items
	 * of the declaration (`'items'`, `'traits'`).
	 * @template T of Node
	 * @param  T  $node
	 * @param  NodeList<T>  $list
	 */
	public static function splitItems(Node $node, NodeList $list, string $slot, string $eol): void
	{
		$items = $node->$slot;
		assert($items instanceof SeparatedNodeList);
		$members = $items->getItems();
		$index = $list->indexOf($node);
		$indentation = $node->getFirstToken()?->getIndentation() ?? '';
		$trailing = $node->getLastToken()->trailingTrivia ?? [];
		$end = new Trivia(TriviaKind::EndOfLine, $eol);
		foreach (array_slice($members, 1) as $i => $member) {
			$copy = clone $node;
			$copied = $copy->$slot;
			assert($copied instanceof SeparatedNodeList);
			foreach ($copied->getItems() as $j => $item) {
				if ($j !== $i + 1) {
					$copied->removeItem($item);
				}
			}

			$copy->setEdgeTrivia([new Trivia(TriviaKind::Whitespace, $indentation)], $i === count($members) - 2 ? $trailing : [$end]);
			$list->insert($index + $i + 1, $copy);
		}

		foreach (array_slice($members, 1) as $member) {
			$items->removeItem($member);
		}

		$node->setEdgeTrivia(trailing: [$end]);
	}
}
