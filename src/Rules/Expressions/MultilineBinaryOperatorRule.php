<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * A family of one boolean operator (`&&`, `||`, `and`, `or`, `xor`), of concatenations or of a bitwise `|` joining flags
 * on a line wider than the line length of the style has every operand on a line of its own, each operator opening the
 * line of the operand after it, unless an outer construct on the line is spread first or `file.lineLengthExcept` names
 * `binaryOperator`. A family is the operands one operator joins without parentheses between them; another operator or
 * parentheses begin an inner family, which is measured once the outer one is spread. A boolean operator chaining
 * a condition is the matter of `MultilineConditionRule`, a family its author spread stays as it is, and arithmetic,
 * the other bitwise operators, comparisons and `??` are not spread. Where the lines stand is the matter of
 * `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineBinaryOperatorRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'multiline.binaryOperator',
			new Words(['perLine' => 'every operand on a line of its own, the operator opening it']),
			'The operands of `&&`, `||`, `and`, `or`, `xor`, `.` and `|` on a line wider than the line length, outside a condition',
			notes: ['A family its author spread over lines stays as it is'],
		)];
	}


	public function getClaims(): array
	{
		// the rules of the spacing move an operator ending its line, which leaves the family spread and this rule silent
		return [BinaryOpNode::class => ['operator' => [
			fn(Gap $gap) => $this->claimForWidth($gap, $gap->token->parent, Line::Next),
			fn(Gap $gap) => $this->claimForWidth($gap, $gap->token->parent, Line::Same),
		]]];
	}


	/** The line of an operator of a family spread for its width, or of the operand after it; null when it stays. */
	private function claimForWidth(Gap $gap, ?Node $operation, Line $line): ?Claim
	{
		if (!$operation instanceof BinaryOpNode) {
			return null;
		}

		$family = NodeHelpers::findOperatorFamily($operation);
		$because = $family === null ? null : $gap->once($family, fn() => self::findWidthReason($family, $gap));
		return $because === null ? null : new Claim(line: $line, because: $because);
	}


	/**
	 * Why a family on one line is spread: its line is wider than the line length of the style and no outer construct
	 * on the line is spread first; measured once the line is indented.
	 */
	private static function findWidthReason(BinaryOpNode $family, Gap $gap): ?string
	{
		$frame = NodeHelpers::findSplitFrame($family);
		if ($frame === null || $frame[3] || !$gap->style->lineSplits->has($frame[0])) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($family, $frame[1], $gap);
		return $width !== null && NodeHelpers::isLineInPlace($gap, $frame[1])
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}
}
