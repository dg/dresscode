<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Token;


/**
 * The line breaks around the operators of a family, the operands one operator of a place joins without parentheses
 * between them, another operator or parentheses beginning an inner family: a family on a line wider than the line
 * length of the style is spread in the first shape of `multiline.items.<place>`, unless an outer construct on the line
 * is spread first or `file.lineLengthExcept` names the place, and one its author spread stays unless its operands
 * share lines where `fill` is not accepted; `perLine` puts every operand on a line of its own. An operator at a line
 * break stands where `multiline.operatorPosition.<place>` says, at the start of the line where it says nothing.
 * The places are `logical`, `concatenation`, `arithmetic`, `bitwise`, `coalesce` and `pipe`; a boolean operator
 * chaining a condition is the matter of `MultilineConditionRule`, a comparison of `BinaryOperatorSpacingRule`. Where
 * the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineBinaryOperatorRule extends GapRule
{
	private const Places = [
		'logical' => '`&&`, `||`, `and`, `or` and `xor` outside a condition',
		'concatenation' => '`.`',
		'arithmetic' => '`+`, `-`, `*`, `/`, `%` and `**`',
		'bitwise' => '`|`, `&`, `^`, `<<` and `>>`',
		'coalesce' => '`??`',
		'pipe' => '`|>`',
	];

	/** @var array<string, ListLayout> place => its shapes */
	private array $layouts = [];

	/** @var array<string, bool> place => whether an operator ending a line moves to the start of the next one */
	private array $operatorsAtStart = [];


	public static function getDecisions(): array
	{
		$decisions = [];
		foreach (self::Places as $place => $operators) {
			array_push($decisions, ...ListLayout::createDecisions(
				$place,
				'operand',
				'operands',
				"The operands of $operators spread over lines by their author or for the width of their line",
				notes: ['Not about the operators of one place joined by another, which begin a family of their own'],
			));
			$decisions[] = new Decision(
				"multiline.operatorPosition.$place",
				Domain::lineStart(),
				"Where $operators stand at a line break",
			);
		}

		return $decisions;
	}


	public function configure(Values $values): void
	{
		foreach (array_keys(self::Places) as $place) {
			$this->layouts[$place] = ListLayout::fromValues($values, $place);
			$this->operatorsAtStart[$place] = !$values->isKept("multiline.operatorPosition.$place");
		}
	}


	public function getClaims(): array
	{
		return [BinaryOpNode::class => ['operator' => [
			fn(Gap $gap) => $this->claimLine($gap, Line::Next),
			fn(Gap $gap) => $this->claimLine($gap, Line::Same),
		]]];
	}


	/**
	 * The line of an operator, before it as the given line and after it as the other one, where its family is spread
	 * or the operator moves to the start of the line it ends; null where it stays.
	 */
	private function claimLine(Gap $gap, Line $line): ?Claim
	{
		$operation = $gap->token->parent;
		$family = $operation instanceof BinaryOpNode ? NodeHelpers::findOperatorFamily($operation) : null;
		$place = $family === null ? null : NodeHelpers::findOperatorPlace($family);
		if ($family === null || $place === null) {
			return null;
		}

		$because = $gap->once($family, fn() => $this->findReasonToSpread($family, $place, $gap));
		return match (true) {
			$because !== null => new Claim(line: $line, because: $because, decision: "multiline.items.$place"),
			$this->operatorsAtStart[$place] && self::isMovedToStart($gap->token, $gap)
				=> new Claim(line: $line, decision: "multiline.operatorPosition.$place"),
			default => null,
		};
	}


	/**
	 * Why every operand of the family takes a line of its own: the family on one line is too wide, or its author spread
	 * it with operands sharing lines where `fill` is not accepted; null where the family stays.
	 */
	private function findReasonToSpread(BinaryOpNode $family, string $place, Gap $gap): ?string
	{
		$layout = $this->layouts[$place];
		$frame = NodeHelpers::findSplitFrame($family);
		if ($frame === null || $layout->items === []) {
			return null;
		} elseif (!$frame[3]) {
			return $layout->getFirst()[0] === ListLayout::PerLine ? NodeHelpers::findWidthReason($family, $gap) : null;
		}

		return $layout->findShapes($frame[1], null)[0] === ListLayout::PerLine
			&& array_any($frame[2], fn(Token $operator) => !$operator->startsLine() && !$operator->isFollowedByLineEnding())
			? 'the operands share lines'
			: null;
	}


	/** Whether the operator ends its line and goes to the start of the next one, decided once per pass about it. */
	private static function isMovedToStart(Token $operator, Gap $gap): bool
	{
		$operation = $operator->parent;
		return $operation instanceof BinaryOpNode && $gap->once($operation, $operator->isFollowedByLineEnding(...), 'moved');
	}
}
