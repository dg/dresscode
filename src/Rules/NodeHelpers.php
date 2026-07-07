<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Gap, Line, Style};
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ArgumentListNode, ArgumentNode, AttributeNode, ElseifNode, Expression, ExpressionNode, MatchArmNode, Scalar, SeparatedNodeList, Statement, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Member\MethodNode;
use function count, strlen;


/**
 * Queries and constructions over the tree that rules share.
 * @internal
 */
final class NodeHelpers
{
	/** a list spread for its width with more items than this lets them fill its lines with `asWritten`, not a line each */
	public const MaxItemsOnOwnLines = 5;


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
	 * The boolean operators chaining a condition, in the order they are written; parentheses and negations end the
	 * chain.
	 * @return list<Token>
	 */
	public static function collectConditionOperators(ExpressionNode $expression): array
	{
		return $expression instanceof Expression\BinaryOpNode && $expression->isLogical()
			? [
				...self::collectConditionOperators($expression->left),
				$expression->operator,
				...self::collectConditionOperators($expression->right),
			]
			: [];
	}


	/**
	 * The boolean operators a condition is built of, seen through the parentheses and the negations around them; one
	 * inside an argument, a `match` arm or a closure is not the condition's and does not count.
	 */
	public static function countConditionOperators(ExpressionNode $expression): int
	{
		return match (true) {
			$expression instanceof Expression\BinaryOpNode && $expression->isLogical()
				=> 1 + self::countConditionOperators($expression->left) + self::countConditionOperators($expression->right),
			$expression instanceof Expression\ParenthesizedNode, $expression instanceof Expression\UnaryOpNode
				=> self::countConditionOperators($expression->expression),
			default => 0,
		};
	}


	/**
	 * The outermost operation of the family the operation belongs to: the operands one boolean operator, `.` or `|`
	 * joins without parentheses between them, another operator or parentheses beginning another family. Null for any
	 * other operator, and for a boolean operator chaining a condition, which is its statement's.
	 */
	public static function findOperatorFamily(Expression\BinaryOpNode $operation): ?Expression\BinaryOpNode
	{
		if (!$operation->isLogical() && !$operation->operator->is(['.', '|'])) {
			return null;
		}

		while (
			$operation->parent instanceof Expression\BinaryOpNode
			&& strcasecmp($operation->parent->operator->text, $operation->operator->text) === 0
		) {
			$operation = $operation->parent;
		}

		return $operation->isLogical() && self::findConditionStatement($operation) !== null ? null : $operation;
	}


	/**
	 * Whether the lines of the tokens have the indentation the run gives them (`Analyses\IndentationSnapshot`), or nothing
	 * places them: a decision by the width of a line waits for it, and a pass later takes it over the right indentation.
	 */
	public static function isLineInPlace(Gap $gap, Token ...$tokens): bool
	{
		$snapshot = $gap->findAnalysis(IndentationSnapshot::class);
		return $snapshot === null || array_all($tokens, fn(Token $token) => $snapshot->isLineInPlace($token));
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
	 * The construct the node is, of a kind `LineSplits` names, with the token its frame begins with, the tokens its shape
	 * puts at the start of a line once spread, and whether it is spread over lines already; null for a node that is none
	 * or has nothing to spread: a call, `new` or an attribute without arguments, a first-class callable, a signature
	 * without parameters, a condition joined by no boolean operator, a chain of fewer than two method calls, an inner
	 * link of a chain or of a family of operators, a `for` with empty sections, an array of fewer than two items, a list
	 * of a single interface, a `match` arm of a single condition. The last is whether the construct is a message, a call
	 * whose sole argument is a string, which is never spread for its width and leaves what it holds whole too. A call
	 * left on its line by the author is not spread even where its last argument, a closure or an array, spans lines.
	 * @return ?array{string, Token, list<Token>, bool, bool}
	 */
	public static function findSplitFrame(Node $node): ?array
	{
		if ($node instanceof ArgumentListNode) {
			$kind = match (true) {
				$node->parent instanceof Expression\FunctionCallNode,
				$node->parent instanceof Expression\MethodCallNode,
				$node->parent instanceof Expression\StaticMethodCallNode,
				$node->parent instanceof Expression\ExitNode => 'call',
				$node->parent instanceof Expression\NewNode, $node->parent instanceof AnonymousClassNode => 'new',
				$node->parent instanceof AttributeNode => 'attribute',
				default => null,
			};
			$items = $node->items->getItems();
			return $kind === null || $items === [] || $items[0] instanceof VariadicPlaceholderNode
				? null
				: [
					$kind,
					$node->openParen,
					[...array_map(fn(Node $item) => $item->getFirstToken(), $items), $node->closeParen],
					self::isMultiline($node->openParen, $items, $node->closeParen),
					self::isSoleStringArgument($node),
				];

		} elseif ($node instanceof Statement\FunctionNode || $node instanceof MethodNode) {
			$params = $node->parameters->getItems();
			return $params === []
				? null
				: [
					'signature',
					$node->openParen,
					[...array_map(fn(Node $param) => $param->getFirstToken(), $params), $node->closeParen],
					self::isMultiline($node->openParen, $params, $node->closeParen),
					false,
				];

		} elseif (
			$node instanceof Statement\IfNode
			|| $node instanceof ElseifNode
			|| $node instanceof Statement\WhileNode
			|| $node instanceof Statement\DoWhileNode
		) {
			return self::countConditionOperators($node->condition) === 0
				? null
				: [
					'condition',
					$node->openParen,
					[$node->condition->getFirstToken(), ...self::collectConditionOperators($node->condition), $node->closeParen],
					$node->openParen->getCurrentLine() !== $node->closeParen->getCurrentLine(),
					false,
				];

		} elseif (
			($node instanceof Expression\MethodCallNode || $node instanceof Expression\PropertyFetchNode)
			&& !(($node->parent instanceof Expression\MethodCallNode || $node->parent instanceof Expression\PropertyFetchNode)
				&& $node->parent->object === $node)
		) {
			$calls = 0;
			$operators = [];
			for (
				$link = $node;
				$link instanceof Expression\MethodCallNode || $link instanceof Expression\PropertyFetchNode;
				$link = $link->object
			) {
				$calls += $link instanceof Expression\MethodCallNode ? 1 : 0;
				$operators[] = $link->operator;
			}

			return $calls < 2
				? null
				: [
					'chain',
					$node->getFirstToken(),
					$operators,
					array_any($operators, fn(Token $operator) => $operator->startsLine()),
					false,
				];

		} elseif ($node instanceof Statement\ForNode) {
			$items = [...$node->initializers->getItems(), ...$node->conditions->getItems(), ...$node->updates->getItems()];
			return $items === []
				? null
				: [
					'for',
					$node->openParen,
					array_values(array_filter([$node->firstSemicolon->getNext(), $node->secondSemicolon->getNext(), $node->closeParen])),
					self::isMultiline($node->openParen, $items, $node->closeParen)
					|| $node->firstSemicolon->startsLine()
					|| $node->secondSemicolon->startsLine(),
					false,
				];

		} elseif ($node instanceof Expression\ArrayNode) {
			$items = $node->items->getItems();
			return count($items) < 2
				? null
				: [
					'array',
					$node->openDelimiter,
					[...array_filter(array_map(fn(Node $item) => $item->getFirstToken(), $items)), $node->closeDelimiter],
					self::isMultiline($node->openDelimiter, $items, $node->closeDelimiter),
					false,
				];

		} elseif ($node instanceof Expression\BinaryOpNode && self::findOperatorFamily($node) === $node) {
			$operators = self::collectFamilyOperators($node, $node->operator->text);
			return [
				'binaryOperator',
				$node->getFirstToken(),
				$operators,
				array_any($operators, fn(Token $operator) => $operator->startsLine() || $operator->isFollowedByLineEnding()),
				false,
			];

		} elseif (
			$node instanceof SeparatedNodeList
			&& ((($node->parent instanceof Statement\ClassNode
				|| $node->parent instanceof Statement\EnumNode
				|| $node->parent instanceof AnonymousClassNode)
				&& $node->parent->implements === $node)
				|| ($node->parent instanceof Statement\InterfaceNode && $node->parent->extends === $node))
		) {
			$names = array_map(fn(Node $item) => $item->getFirstToken(), $node->getItems());
			return count($names) < 2
				? null
				: [
					'implements',
					$names[0],
					[...$names, $node->parent->openBrace],
					array_any($names, fn(Token $name) => $name->startsLine()),
					false,
				];

		} elseif ($node instanceof SeparatedNodeList && $node->parent instanceof MatchArmNode && $node->parent->values === $node) {
			$values = array_map(fn(Node $value) => $value->getFirstToken(), $node->getItems());
			return count($values) < 2
				? null
				: [
					'matchArm',
					$values[0],
					$values,
					array_any(array_slice($values, 1), fn(Token $value) => $value->startsLine()),
					false,
				];

		} elseif ($node instanceof Expression\TernaryNode) {
			return [
				'ternary',
				$node->getFirstToken(),
				[$node->question, $node->colon],
				$node->question->startsLine() || $node->colon->startsLine(),
				false,
			];
		}

		return null;
	}


	/**
	 * The operators of the family the outermost operation stands for, in the order they are written.
	 * @return list<Token>
	 */
	private static function collectFamilyOperators(Expression\BinaryOpNode $operation, string $operator): array
	{
		$operators = [];
		foreach ([$operation->left, $operation->right] as $i => $operand) {
			if ($i === 1) {
				$operators[] = $operation->operator;
			}

			if ($operand instanceof Expression\BinaryOpNode && strcasecmp($operand->operator->text, $operator) === 0) {
				array_push($operators, ...self::collectFamilyOperators($operand, $operator));
			}
		}

		return $operators;
	}


	/**
	 * The width of the line of the construct beginning with the token where it is wider than the line length of the
	 * style and the construct is the one to spread for it; null where the line fits or an outer construct on it is
	 * spread first: one of a kind the run spreads by the width (`Style::$lineSplits`) whose frame begins on the line
	 * and is not spread yet; where what holds the construct keeps it whole (`isHeldWhole()`), a message, the offset
	 * of an array access or an interpolated string; where the line is opened by text, the end of a heredoc or markup,
	 * which leaves the lines spread from it no indentation to count from; where a comment follows the code of the
	 * line, which would stay on its last line, away from the code it speaks of or silences; where another construct to
	 * spread begins after it on the line (`isFollowedBySplit()`); and where what follows it on the line, which stays on
	 * its last line, is too wide by itself. The outer one is spread first and the
	 * inner one measured on the line it is left on, up to the first token a spread outer frame puts at the start of
	 * a line, which its rule moves away later in the pass: the next argument, section, operator or link, or the
	 * closing parenthesis. The width is that of the code, a comment after it left out (`measureCodeLine()`).
	 */
	public static function measureOverflow(Node $construct, Token $open, Gap $gap): ?int
	{
		$style = $gap->style;
		$limit = $style->maxLineLength;
		// the line first, the ancestors only where it is too wide
		$width = $limit === null ? null : self::measureCodeLine($open, $style);
		if ($width === null || $width <= $limit) {
			return null;
		}

		// a line the text opens, after a heredoc or markup, has no indentation the lines spread from it could count from
		$opener = $open;
		while (!$opener->startsLine() && ($previous = $opener->getPrevious()) !== null) {
			$opener = $previous;
		}

		if ($opener->getPrevious() !== null && !Indentation::opensLine($opener)) {
			return null;
		}

		// a comment after the code would stay on the last line of what is spread, away from the code it speaks of or
		// silences
		$last = $open;
		while (($next = $last->getNext()) !== null && !$next->startsLine()) {
			$last = $next;
		}

		if ($last->hasTrailingComment()) {
			return null;
		}

		$line = $open->getCurrentLine();
		$stops = [];
		if (self::isHeldWhole($construct)) {
			return null;
		}

		for ($node = $construct->parent; $node !== null; $node = $node->parent) {
			$frame = self::findSplitFrame($node);
			if ($frame === null || $frame[4] || !$style->lineSplits->has($frame[0])) {
				continue;
			} elseif (!$frame[3] && $frame[1]->getCurrentLine() === $line) {
				if ($node instanceof ArgumentListNode && self::findHuggedFrame($node, $style) !== null) {
					continue; // it stays, its sole argument spreads instead
				}

				return null;

			} elseif ($frame[3]) {
				foreach ($frame[2] as $token) {
					$stops[spl_object_id($token)] = true;
				}
			}
		}

		$width = $stops === [] ? $width : self::measureCodeLine($open, $style, $stops);
		if ($width <= $limit || self::isFollowedBySplit($construct, $style, $stops)) {
			return null;
		}

		// what follows the construct stays on its last line, so spreading it narrows no line too wide for that alone
		$after = $construct->getLastToken()?->getNext();
		if ($after !== null && !$after->startsLine() && !isset($stops[spl_object_id($after)])) {
			$rest = $width - self::measureCodeLine($open, $style, $stops + [spl_object_id($after) => true]);
			if (Indentation::advance(0, $open->getLineIndentation(), $style->toPhpSyntax()) + $rest > $limit) {
				return null;
			}
		}

		return $width;
	}


	/**
	 * Whether a construct the run spreads for its width begins after the construct on its line, before the first of
	 * the stops: of two constructs on a line with no common one to spread, the last is spread first, as the last
	 * argument or the body of a `match` arm is, and the other one is measured again in the next pass.
	 * @param  array<int, true>  $stops  the tokens by `spl_object_id()`
	 */
	private static function isFollowedBySplit(Node $construct, Style $style, array $stops): bool
	{
		for (
			$token = $construct->getLastToken()?->getNext();
			$token !== null && !$token->startsLine() && !isset($stops[spl_object_id($token)]);
			$token = $token->getNext()
		) {
			for ($node = $token->parent; $node !== null && $node->getFirstToken() === $token; $node = $node->parent) {
				$frame = self::findSplitFrame($node);
				if ($frame !== null && !$frame[3] && !$frame[4] && $style->lineSplits->has($frame[0]) && !self::isHeldWhole($node)) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Whether what holds the node keeps it whole whatever the width of its line: the offset of an array access, which
	 * stays on the line of what it reads, a message not spread over lines, and a string the node is interpolated in,
	 * whose whitespace is its value.
	 */
	private static function isHeldWhole(Node $node): bool
	{
		for ($child = $node, $parent = $node->parent; $parent !== null; $child = $parent, $parent = $parent->parent) {
			$frame = $parent instanceof ArgumentListNode ? self::findSplitFrame($parent) : null;
			if (
				($parent instanceof Expression\ArrayAccessNode && $parent->index === $child)
				|| $parent instanceof Scalar\InterpolatedStringNode
				|| $parent instanceof Scalar\HeredocNode
				|| ($frame !== null && $frame[4] && !$frame[3])
			) {
				return true;
			}
		}

		return false;
	}


	/**
	 * The construct the sole argument of the call is, which a line too wide spreads in place of the call where the
	 * style hugs it (`LineSplits::$huggedSoleArgument`): a call or `new` with no other call in what it is made on, or
	 * an array, passed by its name or not, which is of a kind the run spreads, has something to spread and is not
	 * spread yet; null where the call spreads itself.
	 */
	public static function findHuggedFrame(ArgumentListNode $list, Style $style): ?Node
	{
		$items = $list->items->getItems();
		$value = count($items) === 1 && $items[0] instanceof ArgumentNode && $items[0]->ellipsis === null ? $items[0]->value : null;
		$inner = match (true) {
			!$style->lineSplits->huggedSoleArgument => null,
			$value instanceof Expression\FunctionCallNode,
			$value instanceof Expression\MethodCallNode,
			$value instanceof Expression\StaticMethodCallNode,
			$value instanceof Expression\NewNode => $value->arguments,
			$value instanceof Expression\ArrayNode => $value,
			default => null,
		};
		// a call in what the call is made on, a chain among them, would spread before the arguments
		foreach ($inner instanceof ArgumentListNode ? $value->getChildren() : [] as $child) {
			if ($child instanceof Node && $child !== $inner && $child->find(ArgumentListNode::class) !== []) {
				return null;
			}
		}

		$frame = $inner === null ? null : self::findSplitFrame($inner);
		return $frame !== null && !$frame[3] && !$frame[4] && $style->lineSplits->has($frame[0]) ? $inner : null;
	}


	/**
	 * The width of the code on the line of the token, up to the end of the last token on it or the one before the
	 * first of the stops after the token, a comment after it left out, so that no comment makes a construct split;
	 * a tab counts to the next stop of the style. Read from the text around the token, which costs less than a column
	 * the index of the file would count.
	 * @param  array<int, true>  $stops  the tokens by `spl_object_id()`
	 */
	public static function measureCodeLine(Token $token, Style $style, array $stops = []): int
	{
		$head = '';
		$pieces = array_reverse($token->leadingTrivia);
		for ($current = $token; $current !== null; $current = $current->getPrevious()) {
			$pieces ??= [...array_reverse($current->trailingTrivia), $current, ...array_reverse($current->leadingTrivia)];
			foreach ($pieces as $piece) {
				$length = strcspn(strrev($piece->text), "\r\n");
				$head = substr($piece->text, strlen($piece->text) - $length) . $head;
				if ($length < strlen($piece->text)) {
					break 2;
				}
			}

			$pieces = null;
		}

		$phpSyntax = $style->toPhpSyntax();
		$width = $end = Indentation::advance(0, $head, $phpSyntax);
		for ($current = $token; $current !== null; $current = $current->getNext()) {
			if ($current !== $token && isset($stops[spl_object_id($current)])) {
				break;
			}

			foreach ($current === $token ? [] : $current->leadingTrivia as $trivia) {
				if (strcspn($trivia->text, "\r\n") < strlen($trivia->text)) {
					break 2;
				}

				$width = Indentation::advance($width, $trivia->text, $phpSyntax);
			}

			$length = strcspn($current->text, "\r\n");
			$width = $end = Indentation::advance($width, substr($current->text, 0, $length), $phpSyntax);
			if ($length < strlen($current->text)) {
				break;
			}

			foreach ($current->trailingTrivia as $trivia) {
				if (strcspn($trivia->text, "\r\n") < strlen($trivia->text)) {
					break 2;
				}

				$width = Indentation::advance($width, $trivia->text, $phpSyntax);
			}
		}

		return $end;
	}


	/**
	 * Whether the sole argument of the call is a string, an interpolated one, a heredoc, or a concatenation with
	 * a string among its operands, parentheses around them aside: a message, which a line too wide does not split.
	 */
	private static function isSoleStringArgument(ArgumentListNode $list): bool
	{
		$items = $list->items->getItems();
		return count($items) === 1
			&& $items[0] instanceof ArgumentNode
			&& $items[0]->ellipsis === null
			&& self::holdsString($items[0]->value);
	}


	private static function holdsString(ExpressionNode $expression): bool
	{
		return match (true) {
			$expression instanceof Expression\ParenthesizedNode => self::holdsString($expression->expression),
			$expression instanceof Scalar\StringNode,
			$expression instanceof Scalar\InterpolatedStringNode,
			$expression instanceof Scalar\HeredocNode => true,
			$expression instanceof Expression\BinaryOpNode && $expression->operator->is('.') => self::holdsString($expression->left)
				|| self::holdsString($expression->right),
			default => false,
		};
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
}
