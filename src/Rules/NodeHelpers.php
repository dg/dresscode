<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\{Analyses, Gap, RuleContext};
use DressCode\Rules\Namespaces\ImportNotationRule;
use DressCode\Rules\Whitespace\IndentationRule;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Parser, SymbolKind, Token, TokenKind, Trivia, TriviaKind, UnqualifiedResolution};
use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, AttributeGroupNode, CatchNode, ClosureUseNode, ElseIfNode, Expression, ExpressionNode, NameNode, NodeList, Scalar, SeparatedNodeList, Statement, StatementNode, StaticVariableNode, UseItemNode};
use function array_slice, assert, count;


/**
 * Queries and constructions over the tree that rules share.
 * @internal
 */
final class NodeHelpers
{
	private const BooleanOperators = [
		TokenKind::IsEqual, TokenKind::IsNotEqual, TokenKind::IsIdentical, TokenKind::IsNotIdentical,
		'<', '>', TokenKind::IsSmallerOrEqual, TokenKind::IsGreaterOrEqual,
		...self::LogicalOperators,
	];

	private const LogicalOperators = [
		TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor,
	];


	/**
	 * Whether the declaration says of itself that it is deprecated, by the `@deprecated` annotation or by the
	 * `#[\Deprecated]` attribute of PHP 8.4.
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
	 * The if, elseif, while or do-while whose condition the operation is a part of, reached from the condition down
	 * through logical operators alone, not through parentheses or a negation; null for any other operation.
	 */
	public static function findConditionStatement(
		Expression\BinaryOpNode $operation,
	): Statement\IfNode|ElseIfNode|Statement\WhileNode|Statement\DoWhileNode|null
	{
		for ($node = $operation; self::isLogicalOperation($node); $node = $parent) {
			$parent = $node->parent;
			if (
				$parent instanceof Statement\IfNode
				|| $parent instanceof ElseIfNode
				|| $parent instanceof Statement\WhileNode
				|| $parent instanceof Statement\DoWhileNode
			) {
				return $parent->condition === $node ? $parent : null;
			}
		}

		return null;
	}


	/**
	 * Whether the node is an operation of `&&`, `||`, `and`, `or` or `xor`, the operators the parts of a condition
	 * are chained with.
	 * @phpstan-assert-if-true Expression\BinaryOpNode $node
	 */
	public static function isLogicalOperation(?Node $node): bool
	{
		return $node instanceof Expression\BinaryOpNode && $node->operator->is(...self::LogicalOperators);
	}


	/** Whether a single line break follows the token, with nothing but whitespace between it and the next token. */
	public static function isLineBrokenAfter(Token $token): bool
	{
		$next = $token->getNext();
		if ($next === null || $token->hasCommentUpTo($next)) {
			return false;
		}

		$breaks = 0;
		foreach ([...$token->trailingTrivia, ...$next->leadingTrivia] as $trivia) {
			$breaks += (int) $trivia->isEndOfLine();
		}

		return $breaks === 1;
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
	 * Whether something writes the variable or an element of it: it is assigned, stepped, unset, bound, or taken
	 * by reference.
	 */
	public static function isWritten(Expression\VariableNode $variable): bool
	{
		$node = $variable;
		$parent = $node->parent;
		while ( // destructuring writes every variable inside it, and unset and global take theirs in a list
			($parent instanceof Expression\ArrayAccessNode && $parent->expression === $node)
			|| $parent instanceof Expression\ParenthesizedNode
			|| $parent instanceof Expression\ArrayNode
			|| $parent instanceof Expression\ListNode
			|| $parent instanceof ArrayItemNode
			|| $parent instanceof SeparatedNodeList
		) {
			[$node, $parent] = [$parent, $parent->parent];
		}

		return match (true) {
			$parent instanceof Expression\AssignmentNode,
			$parent instanceof Expression\AssignmentByReferenceNode,
			$parent instanceof Expression\CombinedAssignmentNode => $parent->findSlotOf($node) === 'target',
			$parent instanceof Expression\PrefixOpNode,
			$parent instanceof Expression\PostfixOpNode => true,
			$parent instanceof ArgumentNode, $parent instanceof ClosureUseNode => $parent->ampersand !== null,
			$parent instanceof Statement\ForeachNode => $parent->findSlotOf($node) === 'key' || $parent->findSlotOf($node) === 'value',
			$parent instanceof Statement\UnsetNode,
			$parent instanceof Statement\GlobalNode,
			$parent instanceof StaticVariableNode,
			$parent instanceof CatchNode => true,
			default => false,
		};
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


	/** What a deprecation says, as the end of the message after a colon: on one line and without its period; empty when it says nothing. */
	public static function formatDeprecation(Analyses\Deprecation $deprecation): string
	{
		$description = rtrim((string) preg_replace('~\s+~', ' ', trim($deprecation->description)), '.');
		return $description === '' ? '' : ": $description";
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
	 * default, so other options of it may still find the order wrong), into a group use standing under the namespace of
	 * the name where that rule keeps groups, into the statement of its kind standing there when the shape is combined
	 * or that statement lists several names, else with a statement of its own, else first in the scope behind its
	 * declare statements, a blank line apart.
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
		$host = $group = null;
		foreach ($items as $stmt) {
			if (!$stmt instanceof Statement\UseNode || $stmt->kind !== $kind) {
				continue;
			} elseif (!$stmt->isGroup()) {
				$host = $host === null || $precedes(self::firstName($stmt)) ? $stmt : $host;
			} elseif (self::coversName($stmt, $fullName)) {
				$group = $group === null || $precedes(self::firstName($stmt)) ? $stmt : $group;
			}
		}

		$notation = $context->findRule(ImportNotationRule::class);
		if ($group !== null && ($notation?->keepsGroups() ?? true)) {
			$group->addImport($fullName, index: count(array_filter($group->items->getItems(), fn(UseItemNode $item) => $precedes($item->fullName))));
			return;
		}

		$shape = $notation?->getShape($kind);
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
				|| ($stmt->kind === $kind && $precedes(self::firstName($stmt)))
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


	/** The name the order of the imports puts the statement by: the first one it imports, under the prefix of a group. */
	private static function firstName(Statement\UseNode $stmt): string
	{
		$item = $stmt->items->getItems()[0] ?? null;
		return $item === null
			? ''
			: ($stmt->isGroup() ? ltrim($stmt->prefix->text, '\\') . '\\' : '') . trim((string) $item);
	}


	/** Whether the prefix of the group is the namespace the name stands in, so that it can be written as an item of it. */
	private static function coversName(Statement\UseNode $stmt, string $fullName): bool
	{
		$name = ltrim($fullName, '\\');
		$pos = strrpos($name, '\\');
		return $stmt->isGroup()
			&& $pos !== false
			&& strcasecmp(ltrim($stmt->prefix->text, '\\'), substr($name, 0, $pos)) === 0;
	}


	/**
	 * The functions and constants the node declares into a namespace, with a declaration inside a condition or a
	 * function body and a constant define() names with a string: the kind, the fully qualified name and the node that
	 * names it, in the order they are written. What is declared into the global namespace is not among them.
	 * @return list<array{SymbolKind, string, Node}>
	 */
	public static function findNamespacedDeclarations(Node $node, NameResolver $resolver): array
	{
		$declarations = [];
		$candidates = $node->find(Node::class, fn(Node $inner) => $inner instanceof Statement\FunctionNode
			|| $inner instanceof Statement\ConstNode
			|| $inner instanceof Expression\FunctionCallNode);
		foreach ($candidates as $inner) {
			if ($inner instanceof Statement\FunctionNode) {
				$declarations[] = [SymbolKind::Function, (string) $resolver->getDeclaredName($inner), $inner->name];
			} elseif ($inner instanceof Statement\ConstNode) {
				foreach ($inner->items->getItems() as $item) {
					$declarations[] = [SymbolKind::Constant, (string) $resolver->getDeclaredName($item), $item];
				}
			} elseif (
				$inner instanceof Expression\FunctionCallNode
				&& $resolver->isGlobalFunctionCall($inner, 'define')
			) {
				// a name with a leading backslash declares a constant no name reaches, not the one it spells
				$argument = $inner->arguments->findArgument('constant_name', 0)?->value;
				if ($argument instanceof Scalar\StringNode && !str_starts_with($argument->value, '\\')) {
					$declarations[] = [SymbolKind::Constant, $argument->value, $argument];
				}
			}
		}

		return array_values(array_filter($declarations, fn(array $declaration) => str_contains($declaration[1], '\\')));
	}


	/**
	 * Writes the items of a group use as imports of their own, `use A\{B, C as D};` becoming `use A\B;` and
	 * `use A\C as D;`, each on a line of its own with the indentation of the group.
	 * @param NodeList<StatementNode> $list
	 */
	public static function expandGroup(Statement\UseNode $node, NodeList $list, string $eol): void
	{
		$parser = new Parser;
		$statements = [];
		foreach ($node->items->getItems() as $item) {
			$type = match ($item->kind) {
				SymbolKind::Function => 'function ',
				SymbolKind::Constant => 'const ',
				SymbolKind::ClassLike => '',
			};
			$alias = $item->alias === null ? '' : ' as ' . $item->alias->text;
			$statements[] = $parser->parseStatement("use $type{$item->fullName}$alias;");
		}

		$indentation = $node->getFirstToken()?->getIndentation() ?? '';
		$last = array_pop($statements);
		if ($last === null) {
			return;
		}

		$index = $list->indexOf($node);
		$node->replaceWith($last);
		foreach ($statements as $i => $statement) {
			$head = $last->getFirstToken();
			$leading = $i === 0 && $head ? $head->leadingTrivia : [new Trivia(TriviaKind::Whitespace, $indentation)];
			$statement->setEdgeTrivia($leading, [new Trivia(TriviaKind::EndOfLine, $eol)]);
			$list->insert($index + $i, $statement);
		}

		if (count($statements)) {
			$last->setEdgeTrivia(leading: [new Trivia(TriviaKind::Whitespace, $indentation)]);
		}
	}


	/**
	 * A comment standing inside the node or at the end of its last line, which Node::hasComment() does not count;
	 * true for a node without tokens, which nothing can be said of.
	 */
	public static function hasComment(Node $node): bool
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
