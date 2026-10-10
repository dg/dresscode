<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\Analyses\{IndentationPlan, Parameter, PhpSignatures, Types};
use DressCode\{Claim, Gap, Line, RuleContext, Tristate};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, SymbolKind, Token, Trivia};
use PhpSyntax\Nodes\{AnonymousClassNode, ClassLikeNode, ElseifNode, Expression, ExpressionNode, FileNode, IdentifierNode, NameNode, ParameterNode, PlainNodeList, Scalar, SeparatedNodeList, Statement, StatementNode, Type, TypeNode};
use PhpSyntax\Nodes\Member\MethodNode;
use function count, in_array, strlen;


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
		for ($node = $operation; $node instanceof Expression\BinaryOpNode && $node->isLogical(); $node = $parent) {
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
	 * Whether the block, an item of the list, declares a function or a class at the top of a file or a namespace, which
	 * out of the block PHP would bind before the code runs.
	 * @param  PlainNodeList<covariant Node>  $list
	 */
	public static function hasHoistableDeclaration(PlainNodeList $list, Statement\BlockNode $block): bool
	{
		return ($list->parent instanceof FileNode || $list->parent instanceof Statement\NamespaceNode)
			&& array_any(
				$block->statements->getItems(),
				fn(StatementNode $stmt) => $stmt instanceof Statement\FunctionNode || $stmt instanceof ClassLikeNode,
			);
	}


	/**
	 * Whether the lines of the tokens have the indentation the run gives them (`Analyses\IndentationPlan`), or nothing
	 * places them: a decision by the width of a line waits for it, and a pass later takes it over the right indentation.
	 */
	public static function isLineInPlace(Gap $gap, Token ...$tokens): bool
	{
		$plan = $gap->findAnalysis(IndentationPlan::class);
		return $plan === null || array_all($tokens, fn(Token $token) => $plan->isLineInPlace($token));
	}


	/** `2 tabs`, `4 spaces`, `1 tab and 2 spaces`, `none`. */
	public static function describeWidth(string $whitespace): string
	{
		$tabs = substr_count($whitespace, "\t");
		$spaces = strlen($whitespace) - $tabs;
		$parts = [];
		if ($tabs > 0) {
			$parts[] = $tabs === 1 ? '1 tab' : "$tabs tabs";
		}
		if ($spaces > 0) {
			$parts[] = $spaces === 1 ? '1 space' : "$spaces spaces";
		}

		return $parts ? implode(' and ', $parts) : 'none';
	}


	/**
	 * Whether a list in brackets spans lines: a line break after the opening bracket, an item starting a line, or the
	 * closing bracket doing so. A comment after the opening bracket ends its line without making the list span lines.
	 * @param  list<Node>  $items
	 */
	public static function isMultiline(Token $open, array $items, Token $close): bool
	{
		return ($open->getTrailingSpace() === null && !$open->hasComment())
			|| $close->startsLine()
			|| array_any($items, fn(Node $item) => $item->getFirstToken()?->startsLine() === true);
	}


	/**
	 * The negation of the expression as a new detached node with empty trivia on its edges: an equality flips
	 * its operator, `!` is dropped, true and false swap, what binds tightly enough gets `!`, anything else `!(...)`.
	 * An ordering is not flipped, because against NAN both `<` and `>=` are false.
	 */
	public static function negate(ExpressionNode $expression): ExpressionNode
	{
		if ($expression instanceof Expression\BinaryOpNode && ($operator = self::negateComparison($expression->operator))) {
			$copy = $expression->withoutEdgeTrivia();
			$copy->operator = $operator;
			return $copy;
		} elseif ($expression instanceof Expression\UnaryOpNode && $expression->operator->is('!')) {
			$inner = $expression->expression instanceof Expression\ParenthesizedNode ? $expression->expression->expression : $expression->expression;
			return $inner->withoutEdgeTrivia();
		} elseif ($expression instanceof Scalar\BooleanNode) {
			return (new Builder)->value(!$expression->toValue());
		}

		return (new Builder)->unary('!', $expression);
	}


	/** The operator of the opposite equality with the trivia of the given one, null for other operators. */
	private static function negateComparison(Token $operator): ?Token
	{
		$text = match (true) {
			$operator->is(Token::IsEqual) => '!=',
			$operator->is(Token::IsNotEqual) => '==',
			$operator->is(Token::IsIdentical) => '!==',
			$operator->is(Token::IsNotIdentical) => '===',
			default => null,
		};
		return $text === null
			? null
			: Token::fromText($text)
				->setLeadingTrivia($operator->leadingTrivia)
				->setTrailingTrivia($operator->trailingTrivia);
	}


	/**
	 * The parameters of the function, the method or the constructor the call calls, as the declaration in the file,
	 * the signature of PHP or, of a method or a constructor, the types say; null where none of them tells.
	 * @return ?list<Parameter>
	 */
	public static function findParameters(
		Expression\FunctionCallNode|Expression\MethodCallNode|Expression\StaticMethodCallNode|Expression\NewNode $call,
		RuleContext $context,
	): ?array
	{
		if ($call instanceof Expression\FunctionCallNode) {
			if (!$call->name instanceof NameNode) {
				return null;
			}

			$resolver = $context->getAnalysis(NameResolver::class);
			$function = $resolver->resolveFunction($call->name);
			$declaration = $resolver->findDeclaration($function, SymbolKind::Function);
			return match (true) {
				$declaration !== null => array_map(fn(ParameterNode $parameter) => self::toParameter($parameter, $resolver), $declaration->parameters->getItems()),
				$resolver->isGlobalFunctionCall($call) => $context->getAnalysis(PhpSignatures::class)->findParameters($function),
				default => null,
			};
		}

		$types = $context->findAnalysis(Types::class);
		$access = $call instanceof Expression\NewNode ? $types?->findConstructorAccess($call) : $types?->findMemberAccess($call);
		return $access === null ? null : $types->findParameters($access);
	}


	/** The parameter as the declaration says it, the type and the default written as `Parameter` keeps them. */
	public static function toParameter(ParameterNode $parameter, NameResolver $resolver): Parameter
	{
		$default = $parameter->default;
		return new Parameter(
			(string) $parameter->variable->plainName,
			$parameter->type === null ? null : self::describeType($parameter->type, $parameter, $resolver),
			optional: $default !== null || $parameter->ellipsis !== null,
			variadic: $parameter->ellipsis !== null,
			byReference: $parameter->ampersand !== null,
			default: $default instanceof Scalar\StringNode
				|| $default instanceof Scalar\IntegerNode
				|| $default instanceof Scalar\FloatNode
				|| $default instanceof Scalar\BooleanNode
				|| $default instanceof Scalar\NullNode
				|| ($default instanceof Expression\ArrayNode && $default->items->isEmpty())
					? $default->text
					: null,
		);
	}


	/** The type as PHP describes it, a class fully qualified without a leading backslash and `?T` as `T|null`. */
	private static function describeType(TypeNode $type, Node $at, NameResolver $resolver): string
	{
		return match (true) {
			$type instanceof Type\NullableTypeNode => self::describeType($type->type, $at, $resolver) . '|null',
			$type instanceof Type\UnionTypeNode => implode('|', array_map(
				fn(TypeNode $member) => $member instanceof Type\IntersectionTypeNode
					? '(' . self::describeType($member, $at, $resolver) . ')'
					: self::describeType($member, $at, $resolver),
				$type->types->getItems(),
			)),
			$type instanceof Type\IntersectionTypeNode => implode('&', array_map(fn(TypeNode $member) => self::describeType($member, $at, $resolver), $type->types->getItems())),
			$type instanceof Type\NamedTypeNode && $type->isBuiltin() => strtolower($type->name->text),
			$type instanceof Type\NamedTypeNode => $resolver->resolveClass($type->name, $at),
			default => $type->text,
		};
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


	/**
	 * Whether the static context could change what the body of the method does: `$this` anywhere in it, a closure
	 * that inherits it included and an anonymous class that has its own excluded, what `findDynamicVariableAccesses()`
	 * finds, `debug_backtrace()`, which shows the object, `parent::` and a call through `self::`, `static::` or the name
	 * of the class or an ancestor of a method the class does not declare static, which PHP makes with the object;
	 * without the types, any class a class extending another names may be an ancestor. True for a method without
	 * a body.
	 */
	public static function needsObject(MethodNode $method, RuleContext $context): bool
	{
		$class = $method->findAncestor(ClassLikeNode::class);
		if ($method->body === null || $class === null || self::findDynamicVariableAccesses($method->body, $context) !== []) {
			return true;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($method->body->find(Node::class) as $inner) {
			if ($inner->findAncestor(ClassLikeNode::class) !== $class) {
				continue; // what an anonymous class inside does is its own
			}

			if (
				($inner instanceof Expression\VariableNode && $inner->isThis())
				|| ($inner instanceof Expression\StaticMethodCallNode && !self::canCallWithoutObject($inner, $class, $context))
				|| $resolver->isGlobalFunctionCall($inner, 'debug_backtrace')
			) {
				return true;
			}
		}

		return false;
	}


	/** Whether the static call does the same in a static method, where it has no object to pass on. */
	private static function canCallWithoutObject(
		Expression\StaticMethodCallNode $call,
		ClassLikeNode&Node $class,
		RuleContext $context,
	): bool
	{
		if (!$call->class instanceof NameNode) {
			return true;
		} elseif ($call->class->equals('parent') || !$call->name instanceof IdentifierNode) {
			return false;
		} elseif (!in_array(strtolower($call->class->text), ['self', 'static'], true)) {
			$resolver = $context->getAnalysis(NameResolver::class);
			$named = $resolver->resolveClass($call->class);
			$own = $resolver->getDeclaredName($class);
			if ($own === null || strcasecmp($named, $own) !== 0) {
				$extends = $class instanceof Statement\ClassNode || $class instanceof AnonymousClassNode ? $class->extends : null;
				$types = $context->findAnalysis(Types::class);
				return $extends === null || ($own !== null && $types?->isSubtype($own, $named) === Tristate::No);
			}
		}

		foreach ($class->members as $member) {
			if ($member instanceof MethodNode && $member->name->equals($call->name->text)) {
				return $member->modifiers->static;
			}
		}

		return false;
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
			$type = CodeWriter::spellImportKind($item->symbolKind);
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
			$statement->setEdgeTrivia($leading, [Trivia::fromText($lineEnding)]);
			$list->insert($index + $i, $statement);
		}

		if (count($statements)) {
			$last->setEdgeTrivia(leading: [new Trivia(Trivia::Whitespace, $indentation)]);
		}
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
	 * The claims before the items of a list spread over lines by their width: an item follows the one before on its
	 * line while the line, the comma after it included, fits into the length, and begins the next one when it does
	 * not; null where an item cannot be measured on one line.
	 * @param  list<Node>  $items
	 * @param  int  $indentation  the width of the indentation of a line of items
	 * @return ?list<Claim>
	 */
	public static function packItems(array $items, int $indentation, int $lineLength, string $because): ?array
	{
		$break = new Claim(line: Line::Next, because: $because);
		$follow = new Claim(line: Line::Same, because: $because);
		$claims = [];
		$column = 0;
		foreach ($items as $i => $item) {
			$width = self::measureNode($item);
			if ($width === null) {
				return null;
			}

			$width++; // the comma after it
			if ($i > 0 && $column + 1 + $width <= $lineLength) {
				$claims[] = $follow;
				$column += 1 + $width;
			} else {
				$claims[] = $break;
				$column = $indentation + $width;
			}
		}

		return $claims;
	}


	/**
	 * Splits a declaration listing several items (`const A = 1, B = 2;`, `public $a, $b;`, `use A, B;`) into
	 * one declaration per item: every item after the first gets a copy of the declaration of its own, the copies
	 * follow the original in its list and the original keeps the first item. The slot names the list of items
	 * of the declaration (`'items'`, `'traits'`), which holds no comment between its items: the split would lose it.
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
		$end = Trivia::fromText($lineEnding);
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
			$copied->getItems()[0]->getFirstToken()?->setLeadingTrivia([]);

			$copy->setEdgeTrivia([new Trivia(Trivia::Whitespace, $indentation)], $i === count($members) - 2 ? $trailing : [$end]);
			$list->insert($index + $i + 1, $copy);
		}

		foreach (array_slice($members, 1) as $member) {
			$items->removeItem($member);
		}

		$node->setEdgeTrivia(trailing: [$end]);
	}
}
