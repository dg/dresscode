<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Claim, Gap, Line};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ElseifNode, Expression, Statement};
use function strlen;


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
