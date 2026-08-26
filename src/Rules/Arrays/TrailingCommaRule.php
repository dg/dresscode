<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ClosureUsesNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, ArrowFunctionNode, ClosureNode, ListNode, MatchNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{FunctionNode, UseNode};
use function count, ord;


/**
 * The closing bracket decides the trailing comma of a list spread over lines: on its own line it asks for the
 * comma, on the line of the last item it forbids one. A list is spread where a line breaks after the opening
 * bracket, an item starts a line or the closing bracket does; one whose items start on the line of the bracket
 * counts as written on one line however many lines an item spans or a comma begins. `multiLine` says which
 * kinds of list get the comma; `singleLine` removes it from a list written on one line and covers arrays,
 * argument lists, `list()` and a group use whatever `multiLine` says, because there the comma is never wanted.
 */
#[RuleInfo(
	'dresscode/trailing-comma',
	Stage::Structure,
	description: 'Puts the trailing comma into a multi-line list and removes it from a one-line one',
)]
final class TrailingCommaRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, true> */
	private array $multiLine = ['arrays' => true];
	private bool $singleLine = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'multiLine' => Expect::listOf(Expect::anyOf('arrays', 'arguments', 'parameters', 'match', 'closureUses', 'imports'))->default(['arrays'])
				->description('Kinds of lists that end with a trailing comma when the closing bracket is on its own line'),
			'singleLine' => Expect::bool(true)
				->description('Removes the trailing comma of an array, argument list, list() or group use written on one line'),
		]);
	}


	public function configure(array $options): void
	{
		$this->multiLine = array_fill_keys($options['multiLine'], true);
		$this->singleLine = $options['singleLine'];
	}


	public function getVisitedTypes(): array
	{
		return [
			ArrayNode::class,
			ArgumentListNode::class,
			ListNode::class,
			FunctionNode::class,
			MethodNode::class,
			ClosureNode::class,
			ArrowFunctionNode::class,
			MatchNode::class,
			ClosureUsesNode::class,
			UseNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$element, $list, $open, $close, $what] = match (true) {
			$node instanceof ArrayNode => ['arrays', $node->items, $node->openDelimiter, $node->closeDelimiter, 'array'],
			$node instanceof ArgumentListNode => ['arguments', $node->items, $node->openParen, $node->closeParen, 'argument list'],
			$node instanceof ListNode => [null, $node->items, $node->openDelimiter, $node->closeDelimiter, 'destructuring'],
			$node instanceof FunctionNode, $node instanceof MethodNode, $node instanceof ClosureNode, $node instanceof ArrowFunctionNode
				=> ['parameters', $node->parameters, $node->openParen, $node->closeParen, 'parameter list'],
			$node instanceof MatchNode => ['match', $node->arms, $node->openBrace, $node->closeBrace, 'match'],
			$node instanceof ClosureUsesNode => ['closureUses', $node->variables, $node->openParen, $node->closeParen, 'closure use list'],
			$node instanceof UseNode && $node->isGroup() => ['imports', $node->items, $node->openBrace, $node->closeBrace, 'group use'],
			default => [null, null, null, null, null],
		};
		if ($list === null) {
			return;
		}

		assert($open instanceof Token && $close instanceof Token);
		$items = $list->getItems();
		$last = $items === [] ? null : $items[count($items) - 1];
		if ($last === null || $last instanceof VariadicPlaceholderNode) {
			return;
		}

		$lastToken = $last->getLastToken();
		if ($lastToken === null) {
			return;
		}

		if (!self::isSpread($open, $items, $close)) {
			if (
				!$this->singleLine
				|| !($node instanceof ListNode || $node instanceof UseNode || $element === 'arrays' || $element === 'arguments')
				|| !$list->hasTrailingSeparator()
			) {
				return;
			}

			$separators = $list->getSeparators();
			$comma = $separators[count($separators) - 1];
			if ($context->report($comma, 'No trailing comma in a one-line list')) {
				$lastToken->setTrailingTrivia([...$lastToken->trailingTrivia, ...$comma->trailingTrivia]);
				$list->setTrailingSeparator(null);
			}

			return;
		}

		if ($element === null || !isset($this->multiLine[$element])) {
			return;
		}

		if ($last->getEndLine() === $close->getLine()) {
			if (
				$list->hasTrailingSeparator()
				&& $context->report($close, "No trailing comma before a closing bracket on the line of the last item of a multi-line $what")
			) {
				$separators = $list->getSeparators();
				$comma = $separators[count($separators) - 1];
				if ($comma->getTrailingSpace() === null) { // a line ending or a comment follows the comma
					$lastToken->setTrailingTrivia([...$lastToken->trailingTrivia, ...$comma->trailingTrivia]);
				}

				$list->setTrailingSeparator(null);
			}

			return;
		}

		if (
			$list->hasTrailingSeparator()
			|| !$context->report($close, "A multi-line $what must end with a trailing comma", byLine: true)
		) {
			return;
		}

		$comma = new Token(ord(','), ',');
		$comma->setTrailingTrivia($lastToken->trailingTrivia);
		$lastToken->setTrailingTrivia([]);
		$list->setTrailingSeparator($comma);
	}


	/**
	 * Whether the list is spread over lines: a line break after the opening bracket, an item starting a line, or
	 * the closing bracket doing so. A comment after the bracket ends its line without spreading the list.
	 * @param  list<Node>  $items
	 */
	private static function isSpread(Token $open, array $items, Token $close): bool
	{
		return ($open->getTrailingSpace() === null && !$open->hasComment())
			|| $close->startsLine()
			|| array_any($items, fn(Node $item) => $item->getFirstToken()?->startsLine() === true);
	}
}
