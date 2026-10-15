<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\ForNode;


/**
 * The header of a `for` spread over lines, by its author or because its line is wider than the line length of the
 * style, has each of its three sections on a line of its own, the first on the line after the opening parenthesis and
 * the closing parenthesis on a line of its own; the expressions of one section stay together and an empty section is
 * a line holding its semicolon. A header on a line too wide stays when an outer construct on the line is spread first
 * or `file.lineLengthExcept` names `for`. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineForRule extends GapRule
{
	public static function getDecisions(): array
	{
		return ListLayout::createDecisions(
			'for',
			'section',
			'sections',
			'The sections of the header of a `for` spread over lines by its author or for the width of its line, the first on the line after the opening parenthesis and the closing one then standing on a line of its own',
			fill: false,
		);
	}


	public function getClaims(): array
	{
		// the break after a semicolon, which keeps its line in front of it
		$after = fn(Gap $gap) => $this->claimToBreak($gap, $gap->token->parent);
		return [ForNode::class => [
			'openParen' => [null, $after],
			'firstSemicolon' => [null, $after],
			'secondSemicolon' => [null, $after],
			'closeParen' => [fn(Gap $gap) => $this->claimToBreak($gap, $gap->token->parent), null],
		]];
	}


	/** The break before a section or the closing parenthesis, with the reason; null when the header stays as it is. */
	private function claimToBreak(Gap $gap, ?Node $node): ?Claim
	{
		if (!$node instanceof ForNode) {
			return null;
		}

		$because = $gap->once($node, fn() => self::findReasonToBreak($node, $gap));
		return $because === null ? null : new Claim(line: Line::Next, because: $because);
	}


	/**
	 * Why the sections take lines of their own: the header spans lines, or it stands on a line wider than the line
	 * length of the style, no outer construct on the line is spread first and the line is indented.
	 */
	private static function findReasonToBreak(ForNode $node, Gap $gap): ?string
	{
		$frame = NodeHelpers::findSplitFrame($node);
		if ($frame === null) {
			return null;
		}

		return $frame[3] ? 'the header spans several lines' : NodeHelpers::findWidthReason($node, $gap);
	}
}
