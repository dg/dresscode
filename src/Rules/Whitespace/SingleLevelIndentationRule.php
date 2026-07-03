<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage, Style};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Indentation, Node, Token, Trivia};
use PhpSyntax\Nodes\FileNode;
use function count;


/**
 * Indentation deepens by a single level at a time, however many brackets a line opens, and comes back
 * only to a level that some line above opened. Which lines go deeper is the business of the rules that
 * know the constructs; this one holds what is true whatever they decide, and therefore only reports:
 * a line two levels below the one before it may be the one that is wrong, or every line around it may be.
 */
#[RuleInfo(
	'dresscode/singleLevelIndentation',
	Stage::Finishing,
	description: 'Reports indentation that deepens by more than a level or returns to a level nothing opened',
)]
final class SingleLevelIndentationRule extends NodeRule implements ConfigurableRule
{
	private bool $singlelineComment = true;

	/** @var list<int>  the levels a line above has opened, innermost last */
	private array $opened = [0];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'singlelineComment' => Expect::bool(true)
				->description('A comment that fits on one line follows the step as well; a note put beside the code often does not'),
		]);
	}


	public function configure(array $options): void
	{
		$this->singlelineComment = $options['singlelineComment'];
	}


	public function getVisitedTypes(): array
	{
		return [FileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FileNode) {
			return;
		}

		$this->opened = [0];
		$style = $context->style;
		for ($token = $node->getFirstToken(); $token !== null; $token = $token->getNext()) {
			$this->stepOverComments($token, $style, $context);
			if (Indentation::opensLine($token)) {
				$this->step($token->getLineIndentation(), $style, $token, $context);
			}
		}
	}


	/** A comment standing on a line of its own is a line like any other, and its indentation is trivia too. */
	private function stepOverComments(Token $token, Style $style, RuleContext $context): void
	{
		$indentation = '';
		$opens = false;
		foreach ($token->leadingTrivia as $trivia) {
			if ($trivia->isLineEnding()) {
				$indentation = '';
				$opens = true;

			} elseif ($trivia->id === Trivia::Whitespace) {
				$indentation = $trivia->text;

			} elseif ($trivia->isComment()) {
				if (
					$opens
					&& !$trivia->inInterpolation
					&& ($this->singlelineComment || str_contains($trivia->text, "\n"))
				) {
					$this->step($indentation, $style, $token, $context, $trivia);
				}

				$opens = false;
			}
		}
	}


	/**
	 * Follows one line and reports where it breaks the step; the levels opened so far grow and shrink
	 * with it, so that a line deeper than anything above it is caught even after a jump back.
	 */
	private function step(
		string $indentation,
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
		$top = $this->opened[count($this->opened) - 1];
		if ($level === $top) {
			return;

		} elseif ($level > $top) {
			if ($level > $top + 1) {
				$context->report($at, 'The indentation must go deeper by a single level', trivia: $trivia, fixable: false);
			}

			$this->opened[] = $level; // taken as opened, or every line below it would be reported too
			return;
		}

		while (count($this->opened) > 1 && $this->opened[count($this->opened) - 1] > $level) {
			array_pop($this->opened);
		}

		if ($this->opened[count($this->opened) - 1] !== $level) {
			$context->report($at, 'The indentation must return to a level opened by a line above', trivia: $trivia, fixable: false);
			$this->opened[] = $level;
		}
	}
}
