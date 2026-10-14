<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ClosureUseListNode, DestructuringNode, SeparatedNodeList, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, ArrowFunctionNode, ClosureNode, IssetNode, MatchNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{FunctionNode, UnsetNode, UseNode};
use function count;


/**
 * Each place says what holds for the trailing comma of its list spread over lines: `required` puts it there when the
 * closing bracket stands on its own line, `optional` leaves it there to the author, and both remove it when the bracket
 * follows the last item; a list written on one line loses it wherever the place is not `keep`, because there the comma
 * is never wanted, and `keep` leaves every comma as it is. A list is spread where a line breaks after the opening bracket,
 * an item starts a line or the closing bracket does; one whose items start on the line of the bracket counts as written
 * on one line however many lines an item spans or a comma begins. A comma is added only where the target allows it, after
 * parameters and in the `use` of a closure from PHP 8.0 on.
 */
#[RuleInfo(Stage::Structure)]
final class TrailingCommaRule extends NodeRule
{
	private const Required = 'required';
	private const Optional = 'optional';
	private const Keep = 'keep';
	private const Path = 'multiline.trailingComma.';

	/** place => the version of PHP that allows its trailing comma, where it is newer than 7.0 */
	private const AllowedSince = ['import' => '7.2', 'argument' => '7.3', 'parameter' => '8.0', 'closureUse' => '8.0'];

	/** @var array<string, string>  place => what holds in a multi-line list */
	private array $places = [
		'array' => self::Keep,
		'argument' => self::Keep,
		'parameter' => self::Keep,
		'matchArm' => self::Keep,
		'closureUse' => self::Keep,
		'import' => self::Keep,
		'list' => self::Keep,
	];


	public static function getDecisions(): array
	{
		$words = new Words([
			self::Required => 'always there where the closing bracket stands on its own line, never where it follows the last item',
			self::Optional => 'as the author wrote it where the closing bracket stands on its own line, never where it follows the last item',
		]);
		$decisions = [];
		foreach ([
			'array' => 'a multi-line array, a destructuring written with `[...]` included',
			'argument' => 'the multi-line arguments of a call and the variables of `isset()` and `unset()`',
			'parameter' => 'a multi-line list of parameters',
			'matchArm' => 'the arms of a multi-line `match`',
			'closureUse' => 'a multi-line `use` of a closure',
			'import' => 'the names of a multi-line group use',
			'list' => 'a multi-line `list()`',
		] as $place => $what) {
			$decisions[] = new Decision(
				self::Path . $place,
				$words,
				"The trailing comma of $what; any value but `keep` also removes the one a list written on one line has",
			);
		}

		return $decisions;
	}


	public function configure(Values $values): void
	{
		foreach ($this->places as $place => &$mode) {
			$value = $values->get(self::Path . $place);
			$mode = $value->isKept() ? self::Keep : $value->getWord();
		}

		unset($mode);
	}


	public function getVisitedNodes(): array
	{
		return [
			ArrayNode::class,
			ArgumentListNode::class,
			IssetNode::class,
			UnsetNode::class,
			DestructuringNode::class,
			FunctionNode::class,
			MethodNode::class,
			ClosureNode::class,
			ArrowFunctionNode::class,
			MatchNode::class,
			ClosureUseListNode::class,
			UseNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$place, $list, $open, $close, $what] = match (true) {
			$node instanceof ArrayNode => ['array', $node->items, $node->openDelimiter, $node->closeDelimiter, 'array'],
			$node instanceof ArgumentListNode => ['argument', $node->items, $node->openParen, $node->closeParen, 'argument list'],
			$node instanceof IssetNode, $node instanceof UnsetNode
				=> ['argument', $node->variables, $node->openParen, $node->closeParen, 'argument list'],
			$node instanceof DestructuringNode => [
				$node->listKeyword === null ? 'array' : 'list',
				$node->items,
				$node->openDelimiter,
				$node->closeDelimiter,
				'destructuring',
			],
			$node instanceof FunctionNode, $node instanceof MethodNode, $node instanceof ClosureNode, $node instanceof ArrowFunctionNode
				=> ['parameter', $node->parameters, $node->openParen, $node->closeParen, 'parameter list'],
			$node instanceof MatchNode => ['matchArm', $node->arms, $node->openBrace, $node->closeBrace, 'match'],
			$node instanceof ClosureUseListNode => ['closureUse', $node->items, $node->openParen, $node->closeParen, 'closure use list'],
			$node instanceof UseNode && $node->isGroup() => ['import', $node->items, $node->openBrace, $node->closeBrace, 'group use'],
			default => [null, null, null, null, null],
		};
		$mode = $place === null ? self::Keep : $this->places[$place];
		if ($list === null || $mode === self::Keep) {
			return;
		}

		assert($place !== null && $open instanceof Token && $close instanceof Token);
		$decision = self::Path . $place;
		$items = $list->getItems();
		$last = $items === [] ? null : $items[count($items) - 1];
		if (
			$last === null
			|| $last instanceof VariadicPlaceholderNode
			|| $last->getLastToken() === null
			|| ($mode !== self::Required && !$list->hasTrailingSeparator()) // a list without the comma lacks only a required one
		) {
			return;
		}

		if (!NodeHelpers::isMultiline($open, $items, $close)) {
			$comma = $list->getTrailingSeparator();
			if ($comma !== null && $context->report($comma, 'A one-line list must not end with a trailing comma.', decision: $decision)) {
				self::removeTrailingComma($list);
			}

			return;
		}

		if ($last->getEndLine() === $close->getCurrentLine()) {
			$message = 'A trailing comma must not precede a closing bracket on the line of the last item.';
			if ($list->hasTrailingSeparator() && $context->report($close, $message, decision: $decision)) {
				self::removeTrailingComma($list);
			}

			return;

		} elseif (
			$mode === self::Optional
			|| $list->hasTrailingSeparator()
			|| version_compare($context->phpVersion, self::AllowedSince[$place] ?? '7.0', '<')
			|| !$context->report($close, "A multi-line $what must end with a trailing comma.", decision: $decision, byLine: true)
		) {
			return;
		}

		$list->setTrailingSeparator(Token::fromText(','));
	}


	/** @param SeparatedNodeList<covariant Node> $list */
	private static function removeTrailingComma(SeparatedNodeList $list): void
	{
		$list->setTrailingSeparator(null);
		$items = $list->getItems();
		$token = $items[count($items) - 1]->getLastToken();
		if ($token?->getTrailingSpace() !== null) { // the space before the bracket goes with the comma
			$token->setTrailingTrivia([]);
		}
	}
}
