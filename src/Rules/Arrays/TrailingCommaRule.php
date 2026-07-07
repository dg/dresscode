<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ClosureUsesNode, ListNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, ArrowFunctionNode, ClosureNode, MatchNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{FunctionNode, UseNode};
use function count, ord;


/**
 * Each place says what holds for the trailing comma of its list spread over lines: `required` puts it there when
 * the closing bracket stands on its own line and removes it when the bracket follows the last item, `optional`
 * leaves it to the author, `forbidden` removes it; a list written on one line loses it wherever the place is not
 * `keep`, because there the comma is never wanted. A list is spread where a line breaks after the opening
 * bracket, an item starts a line or the closing bracket does; one whose items start on the line of the bracket
 * counts as written on one line however many lines an item spans or a comma begins.
 */
#[RuleInfo(
	'dresscode/trailingComma',
	Stage::Structure,
	description: 'Puts the trailing comma into a multi-line list or removes it, and removes it from a one-line one',
)]
final class TrailingCommaRule extends NodeRule implements ConfigurableRule
{
	private const Required = 'required';
	private const Optional = 'optional';
	private const Forbidden = 'forbidden';
	private const Keep = 'keep';

	/** @var array<string, string>  place => what holds in a multi-line list */
	private array $places = [
		'array' => self::Required,
		'argument' => self::Optional,
		'parameter' => self::Keep,
		'matchArm' => self::Keep,
		'closureUse' => self::Keep,
		'import' => self::Optional,
		'list' => self::Optional,
	];


	public static function getOptionsSchema(): Schema
	{
		$place = Expect::anyOf(self::Required, self::Optional, self::Forbidden, self::Keep);
		return Expect::structure([
			'array' => (clone $place)->default(self::Required)
				->description('`required` puts the comma into a multi-line list whose closing bracket stands on its own line, `optional` leaves a multi-line list as it is, `forbidden` removes the comma from it; all three remove it from a list on one line, which `keep` leaves too'),
			'argument' => (clone $place)->default(self::Optional),
			'parameter' => (clone $place)->default(self::Keep),
			'matchArm' => (clone $place)->default(self::Keep),
			'closureUse' => (clone $place)->default(self::Keep),
			'import' => (clone $place)->default(self::Optional)->description('A group use'),
			'list' => Expect::anyOf(self::Optional, self::Keep)->default(self::Optional)
				->description('`list()`, removed from one line only; a destructuring written with `[...]` is `array`'),
		]);
	}


	public function configure(array $options): void
	{
		$this->places = $options;
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
		[$place, $list, $open, $close, $what] = match (true) {
			$node instanceof ArrayNode => ['array', $node->items, $node->openDelimiter, $node->closeDelimiter, 'array'],
			$node instanceof ArgumentListNode => ['argument', $node->items, $node->openParen, $node->closeParen, 'argument list'],
			$node instanceof ListNode => ['list', $node->items, $node->openDelimiter, $node->closeDelimiter, 'destructuring'],
			$node instanceof FunctionNode, $node instanceof MethodNode, $node instanceof ClosureNode, $node instanceof ArrowFunctionNode
				=> ['parameter', $node->parameters, $node->openParen, $node->closeParen, 'parameter list'],
			$node instanceof MatchNode => ['matchArm', $node->arms, $node->openBrace, $node->closeBrace, 'match'],
			$node instanceof ClosureUsesNode => ['closureUse', $node->variables, $node->openParen, $node->closeParen, 'closure use list'],
			$node instanceof UseNode && $node->isGroup() => ['import', $node->items, $node->openBrace, $node->closeBrace, 'group use'],
			default => [null, null, null, null, null],
		};
		$mode = $place === null ? self::Keep : $this->places[$place];
		if ($list === null || $mode === self::Keep) {
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
			if (!$list->hasTrailingSeparator()) {
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

		if ($mode === self::Optional) {
			return;

		} elseif ($mode === self::Forbidden || $last->getEndLine() === $close->currentLine) {
			$message = $mode === self::Forbidden
				? "No trailing comma in a multi-line $what"
				: "No trailing comma before a closing bracket on the line of the last item of a multi-line $what";
			if ($list->hasTrailingSeparator() && $context->report($close, $message)) {
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

		$comma = new Token(ord(','), ',')->setTrailingTrivia($lastToken->trailingTrivia);
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
