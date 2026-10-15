<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\ElseifNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Statement\{DoWhileNode, IfNode, WhileNode};
use function count;


/**
 * A condition of `if`, `elseif`, `while` and `do-while` joined by boolean operators that stands on several lines
 * stands in the shapes of `multiline.items.condition`, how its parts share the lines, and `multiline.edges.condition`,
 * where it begins after the opening parenthesis and where the closing one stands; a condition in no shape the
 * configuration allows is written again only on the axis that misses, the parts each on a line of its own beginning
 * with its boolean operator, and so is one on a single line wider than the line length of the style in the first
 * shapes, unless an outer construct on the line is spread first or `file.lineLengthExcept` names `condition`. The
 * width is that of the code of the line, a tab counting to the next stop of the style and a comment after it left out.
 * A boolean operator ending a line of a condition left as it is moves to the start of the next one, as
 * `multiline.operatorPosition.condition` says. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineConditionRule extends GapRule
{
	private const Position = 'multiline.operatorPosition.condition';

	private ListLayout $layout;
	private bool $operatorsAtStart = false;


	public static function getDecisions(): array
	{
		return [
			...ListLayout::createDecisions(
				'condition',
				'part',
				'parts',
				'A condition of `if`, `elseif`, `while` and `do-while` joined by boolean operators that stands on several lines or on one too wide, a line per part beginning with its operator',
				'`(`',
				'`)`',
			),
			new Decision(
				self::Position,
				Domain::lineStart(),
				'Where a boolean operator chaining a condition of `if`, `elseif`, `while` and `do-while` stands at a line break',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'condition');
		$this->operatorsAtStart = !$values->isKept(self::Position);
	}


	public function getClaims(): array
	{
		$statement = [
			'condition' => [fn(Gap $gap) => $this->decide($gap, $gap->value->parent)['first'] ?? null, null],
			'closeParen' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['close'] ?? null, null],
		];
		return [
			IfNode::class => $statement,
			ElseifNode::class => $statement,
			WhileNode::class => $statement,
			DoWhileNode::class => $statement,
			BinaryOpNode::class => [
				// the part begins with its operator, so what follows the operator stays on its line
				'operator' => [
					fn(Gap $gap) => $this->claimForPart($gap, Line::Next),
					fn(Gap $gap) => $this->claimForPart($gap, Line::Same),
				],
			],
		];
	}


	/**
	 * The break around a boolean operator of the chain of the condition: the one a part on a line of its own asks for
	 * where the parts are written again, otherwise, with the operators at the start, the break after an operator ending
	 * a line moved in front of it.
	 */
	private function claimForPart(Gap $gap, Line $line): ?Claim
	{
		$part = $gap->token->parent;
		$statement = $part instanceof BinaryOpNode ? NodeHelpers::findConditionStatement($part) : null;
		if ($statement === null) {
			return null;
		}

		$parts = $this->decide($gap, $statement)['parts'] ?? null;
		return match (true) {
			$parts !== null => new Claim(line: $line, because: $parts->because, decision: $parts->decision),
			$this->operatorsAtStart && in_array($gap->token, $this->findOperatorsEndingLine($gap, $statement), true)
				=> new Claim(line: $line, decision: self::Position),
			default => null,
		};
	}


	/**
	 * The boolean operators of the chain of the condition that end their line, decided once per pass about the
	 * statement.
	 * @return list<Token>
	 */
	private function findOperatorsEndingLine(Gap $gap, IfNode|ElseifNode|WhileNode|DoWhileNode $statement): array
	{
		return $gap->once($statement, fn() => array_values(array_filter(
			NodeHelpers::collectConditionOperators($statement->condition),
			fn(Token $token) => $token->isFollowedByLineEnding(),
		)));
	}


	/**
	 * What the condition of the statement is written in: the claim of its first part, of the closing parenthesis and
	 * the one standing for the line of every part, null where it stays; null as a whole where nothing changes.
	 * Decided once per pass about the condition, from the shape it has when the first of its gaps is reached.
	 * @return ?array{first: ?Claim, close: ?Claim, parts: ?Claim}
	 */
	private function decide(Gap $gap, ?Node $node): ?array
	{
		if (
			!$node instanceof IfNode
			&& !$node instanceof ElseifNode
			&& !$node instanceof WhileNode
			&& !$node instanceof DoWhileNode
		) {
			return null;
		}

		return $gap->once($node->condition, fn() => $this->claimCondition($node, $gap));
	}


	/**
	 * The claims of a condition on several lines whose shape is not accepted on an axis, written again on that axis,
	 * or of one on a single line too wide, written in the first shapes.
	 * @return ?array{first: ?Claim, close: ?Claim, parts: ?Claim}
	 */
	private function claimCondition(IfNode|ElseifNode|WhileNode|DoWhileNode $node, Gap $gap): ?array
	{
		if (NodeHelpers::countConditionOperators($node->condition) === 0) {
			return null;
		}

		$open = $node->openParen;
		$close = $node->closeParen;
		if ($open->getCurrentLine() === $close->getCurrentLine()) {
			$because = $this->layout->items === [] ? null : NodeHelpers::findWidthReason($node, $gap);
			[$items, $edges] = $this->layout->getFirst();
			return $because === null ? null : self::claimShapes($items, $edges, $because, $this->layout->itemDecision);
		}

		// several lines is a property of the parentheses, not of the chain: a condition on one line between
		// parentheses of their own is written in no shape either
		[$items, $edges] = $this->layout->findShapes($open, $close);
		$edges = $edges === ListLayout::findEdges($open, $close) ? null : $edges;
		$items = $items === ListLayout::PerLine && array_any(
			NodeHelpers::collectConditionOperators($node->condition),
			fn(Token $operator) => !$operator->startsLine() && !$operator->isFollowedByLineEnding(),
		)
			? $items
			: null;
		if ($items === null && $edges === null) {
			return null;
		}

		$parts = self::claimShapes($items, null, 'the parts of the condition share lines', $this->layout->itemDecision);
		$frame = self::claimShapes(
			null,
			$edges,
			count($this->layout->edges) === 1
				? "the condition is not in the `{$this->layout->edges[0]}` shape"
				: 'the condition fits none of the allowed shapes',
			// where the parts are written again too, the edges go with them, so that a line breaking for both is one violation
			$items === null ? $this->layout->edgeDecision : $this->layout->itemDecision,
		);
		return ['first' => $frame['first'], 'close' => $frame['close'], 'parts' => $parts['parts']];
	}


	/**
	 * The claims of the shapes, null for an axis left as it is: the first part on the line of the opening parenthesis
	 * or after it, the closing parenthesis on the line of the last part or on its own, and a line per part.
	 * @return array{first: ?Claim, close: ?Claim, parts: ?Claim}
	 */
	private static function claimShapes(?string $items, ?string $edges, string $because, ?string $decision): array
	{
		$break = new Claim(line: Line::Next, because: $because, decision: $decision);
		$follow = new Claim(line: Line::Same, because: $because, decision: $decision);
		return [
			'first' => match ($edges) {
				ListLayout::Apart => $break,
				ListLayout::Attached, ListLayout::ClosingApart => $follow,
				default => null,
			},
			'close' => match ($edges) {
				ListLayout::Apart, ListLayout::ClosingApart => $break,
				ListLayout::Attached => $follow,
				default => null,
			},
			'parts' => $items === ListLayout::PerLine ? new Claim(because: $because, decision: $decision) : null,
		];
	}
}
