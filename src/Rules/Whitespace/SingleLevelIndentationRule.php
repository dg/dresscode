<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Style, Values};
use DressCode\Domains\Flag;
use PhpSyntax\{Indentation, Node, Token, Trivia};
use PhpSyntax\Nodes\FileNode;
use function count;


/**
 * Indentation deepens by a single level at a time, however many brackets a line opens, and comes back
 * only to a level that some line above opened. Which lines go deeper is the business of the rules that
 * know the constructs; this one holds what is true whatever they decide, and therefore only reports:
 * a line two levels below the one before it may be the one that is wrong, or every line around it may be.
 */
#[RuleInfo(Stage::Finishing)]
final class SingleLevelIndentationRule extends NodeRule
{
	private const Code = 'indentation.singleLevel';
	private const Comment = 'indentation.singleLevelInComments';

	private bool $singlelineComment = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Code, Domain::state('required'), 'A line steps in by one level at most, and only to a level something opened, a comment spanning several lines included'),
			new Decision(self::Comment, new Flag, 'Whether a single-line comment standing on a line of its own steps in as a line of code does', parameter: true, default: false),
		];
	}


	public function configure(Values $values): void
	{
		$this->singlelineComment = $values->get(self::Comment)->getFlag();
	}


	public function getVisitedNodes(): array
	{
		return [FileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FileNode) {
			return;
		}

		$opened = [0]; // the levels a line above has opened, innermost last
		$style = $context->style;
		for ($token = $node->getFirstToken(); $token !== null; $token = $token->getNext()) {
			$this->stepOverComments($token, $opened, $style, $context);
			if (Indentation::opensLine($token)) {
				$this->step($token->getLineIndentation(), $opened, $style, $token, $context);
			}
		}
	}


	/**
	 * A comment standing on a line of its own is a line like any other, and its indentation is trivia too.
	 * @param list<int> $opened
	 */
	private function stepOverComments(Token $token, array &$opened, Style $style, RuleContext $context): void
	{
		if ($token->leadingTrivia === []) {
			return;
		}

		$indentation = '';
		$before = $token->getPrevious()->trailingTrivia ?? [];
		$opens = $before !== [] && $before[count($before) - 1]->isLineEnding();
		foreach ($token->leadingTrivia as $trivia) {
			if ($trivia->isLineEnding()) {
				$indentation = '';
				$opens = true;

			} elseif ($trivia->is(Trivia::Whitespace)) {
				$indentation = $trivia->text;

			} elseif ($trivia->isComment()) {
				$multiline = str_contains($trivia->text, "\n");
				if (
					$opens
					&& !$trivia->inInterpolation
					&& ($this->singlelineComment || $multiline)
				) {
					$this->step($indentation, $opened, $style, $token, $context, $trivia);
				}

				$opens = false;
			}
		}
	}


	/**
	 * Follows one line and reports where it breaks the step; the levels opened so far grow and shrink
	 * with it, so that a line deeper than anything above it is caught even after a jump back.
	 * @param list<int> $opened
	 */
	private function step(
		string $indentation,
		array &$opened,
		Style $style,
		Token $at,
		RuleContext $context,
		?Trivia $trivia = null,
	): void
	{
		$unit = Indentation::measure($style->indent, $style->toPhpSyntax());
		$width = Indentation::measure($indentation, $style->toPhpSyntax());
		// laid out by hand under an opening bracket: not a step, and this rule has nothing to say
		if ($width % $unit !== 0) {
			return;
		}

		$level = intdiv($width, $unit);
		$top = $opened[count($opened) - 1];
		if ($level === $top) {
			return;

		} elseif ($level > $top) {
			if ($level > $top + 1) {
				$context->report($at, 'The indentation must go deeper by a single level.', trivia: $trivia, fixable: false);
			}

			$opened[] = $level; // taken as opened, or every line below it would be reported too
			return;
		}

		while (count($opened) > 1 && $opened[count($opened) - 1] > $level) {
			array_pop($opened);
		}

		if ($opened[count($opened) - 1] !== $level) {
			$context->report($at, 'The indentation must return to a level opened by a line above.', trivia: $trivia, fixable: false);
			$opened[] = $level;
		}
	}
}
