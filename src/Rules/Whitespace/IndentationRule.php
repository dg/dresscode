<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\Analyses\{IndentationPlan, Placement};
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Indentation, Node, Nodes, Style, Token, Trivia};
use function strlen;


/**
 * Every line indented by the construct it continues: what a construct holds stands one level below the line
 * the construct begins on, what closes or continues the construct stands at that line, and the level is
 * counted from the level the construct itself was given, never read from the text around it. Which part of
 * a construct a line is comes from the layout role of the slot it opens (`PhpSyntax\Indentation::findOwner()`,
 * which takes a pipeline for a chain); an operator, a ternary branch, a link of a chain and the cases of a
 * switch step in as the options say, an operator and a branch never by less than a level where the expression
 * shares its line with what holds it or begins its statement. A comment on a line of its own stands with
 * the line below it, above a closing bracket with the content it closes, and its inner lines keep the column of its
 * first line as `indentation.commentLines` says. A line comment in the first column of an indented line, as an editor
 * comments a line out, loses the indentation it doubles after its marker when it moves, or stays where it is as
 * `comments.firstColumn` says. The content of strings, heredocs and inline HTML is text and never changes. Where each
 * line goes is `Analyses\IndentationPlan`.
 */
#[RuleInfo(
	Stage::Finishing,
	modifiesComments: true,
	decisions: [
		IndentationPlan::Unit,
		'indentation.tabWidth',
		IndentationPlan::Binary,
		IndentationPlan::Ternary,
		IndentationPlan::SwitchCase,
		IndentationPlan::Chain,
		'comments.firstColumn',
	],
	analyses: [IndentationPlan::class],
)]
final class IndentationRule extends NodeRule
{
	private const CommentLines = 'indentation.commentLines';
	private const FirstColumn = 'comments.firstColumn';

	private bool $commentLines = true;

	/** whether a line comment may stand in the first column of an indented line, as an editor comments a line out */
	private bool $firstColumnKept = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::CommentLines,
				new Words(
					['aligned' => 'every inner line in the column of the first, the stars of a doc comment one space in from its opening'],
				),
				'The inner lines of a comment on several lines whose first line stands where it should; a comment moved with its line takes its inner lines along under any value',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->commentLines = !$values->isKept(self::CommentLines);
		$this->firstColumnKept = $values->get(self::FirstColumn)->getWord() === 'optional';
	}


	public function getVisitedNodes(): array
	{
		return [Nodes\FileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Nodes\FileNode) {
			return;
		}

		foreach ($context->getAnalysis(IndentationPlan::class)->getPlacements() as $placement) {
			$this->indent($placement, $context);
		}
	}


	private function indent(Placement $placement, RuleContext $context): void
	{
		if ($placement->isInPlace()) {
			return;
		}

		[$token, $indentation, $commentIndentation] = [$placement->token, $placement->indentation, $placement->commentIndentation];
		$decision = $placement->decision;
		$firstColumn = $commentIndentation === null || $commentIndentation === '' ? [] : self::findFirstColumnComments($token);
		$accepted = $this->firstColumnKept ? $firstColumn : [];
		if ($token->getIndentation() !== $indentation || $commentIndentation === null) {
			$subject = (string) preg_replace('~^an? ~', 'the ', $placement->subject);
			$message = self::formatMessage($subject, $indentation, $token->getIndentation());
		} elseif (($found = self::findCommentIndentation($token, $commentIndentation, $accepted)) !== null) {
			$message = self::formatMessage('the comment', $commentIndentation, $found);
		} elseif ($this->commentLines && $accepted === []) {
			$message = 'Expected the inner lines of the comment aligned with its first line.';
			$decision = self::CommentLines;
		} else {
			return;
		}

		if ($context->report(
			$token,
			$message,
			decision: $decision,
			trivia: Indentation::findTrivia($token),
			follows: $placement->follows,
		)) {
			if ($commentIndentation === null) {
				$token->setIndentation($indentation);
				return;
			}

			foreach ($this->firstColumnKept ? [] : $firstColumn as $comment) {
				$token->replaceTrivia(
					$comment,
					self::removeDoubledIndentation($comment, $commentIndentation, $context->style->toPhpSyntax()),
				);
			}

			Indentation::set($token, $indentation, $commentIndentation);
			if ($accepted !== []) {
				self::returnToFirstColumn($token, $accepted);
			}
		}
	}


	private static function formatMessage(string $subject, string $expected, string $found): string
	{
		return $expected === ''
			? "Expected $subject without indentation, " . NodeHelpers::describeWidth($found) . ' found.'
			: "Expected $subject indented by "
				. NodeHelpers::describeWidth($expected)
				. ', '
				. NodeHelpers::describeWidth($found)
				. ' found.';
	}


	/**
	 * The indentation of the first comment on a line of its own above the token that does not have the expected one; null
	 * where every one has it or is accepted where it stands.
	 * @param  list<Trivia>  $accepted
	 */
	private static function findCommentIndentation(Token $token, string $expected, array $accepted): ?string
	{
		$lineStart = true;
		$indentation = '';
		foreach ($token->leadingTrivia as $trivia) {
			if ($trivia->isLineEnding()) {
				[$lineStart, $indentation] = [true, ''];
			} elseif ($lineStart && $trivia->id === Trivia::Whitespace) {
				$indentation = $trivia->text;
			} elseif ($lineStart && $trivia->isComment() && $indentation !== $expected && !in_array($trivia, $accepted, true)) {
				return $indentation;
			} else {
				$lineStart = false;
			}
		}

		return null;
	}


	/**
	 * The `//` and `#` comments on lines of their own above the token that start in the first column, as an editor comments
	 * a line out, the indentation of the line staying after the marker.
	 * @return list<Trivia>
	 */
	private static function findFirstColumnComments(Token $token): array
	{
		$comments = [];
		$lineStart = true;
		foreach ($token->leadingTrivia as $trivia) {
			if ($lineStart && $trivia->isComment() && preg_match('~^(//|#)~', $trivia->text) === 1) {
				$comments[] = $trivia;
			}

			$lineStart = $trivia->isLineEnding();
		}

		return $comments;
	}


	/**
	 * The comment without the indentation the whitespace after its marker doubles, measured as it stands from the first
	 * column: as wide a part as the indentation goes, and the rest stays after a single space, so that the lines of a
	 * block commented out keep their structure.
	 */
	private static function removeDoubledIndentation(Trivia $comment, string $indentation, Style $style): Trivia
	{
		if (preg_match('~^(//|#)([ \t]+)(.*)$~s', $comment->text, $m) !== 1) {
			return $comment;
		}

		[, $marker, $whitespace, $text] = $m;
		$target = Indentation::advance(0, $indentation, $style);
		$width = strlen($marker);
		$cut = 0;
		while ($cut < strlen($whitespace) && ($next = Indentation::advance($width, $whitespace[$cut], $style)) <= $target) {
			[$width, $cut] = [$next, $cut + 1];
		}

		return $comment->withText(rtrim($marker . ' ' . substr($whitespace, $cut) . $text));
	}


	/**
	 * Takes the comments back to the first column, where the indentation of their line moved them along.
	 * @param  list<Trivia>  $comments
	 */
	private static function returnToFirstColumn(Token $token, array $comments): void
	{
		$trivia = $token->leadingTrivia;
		foreach ($trivia as $i => $item) {
			if (in_array($item, $comments, true) && ($trivia[$i - 1] ?? null)?->id === Trivia::Whitespace) {
				unset($trivia[$i - 1]);
			}
		}

		$token->setLeadingTrivia(array_values($trivia));
	}
}
