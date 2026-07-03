<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\Analyses\IndentationPlan;
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Indentation, Node, Nodes, Token, Trivia};


/**
 * Every line indented by the construct it continues: what a construct holds stands one level below the line
 * the construct begins on, what closes or continues the construct stands at that line, and the level is
 * counted from the level the construct itself was given, never read from the text around it. Which part of
 * a construct a line is comes from the layout role of the slot it opens (`PhpSyntax\Indentation::findOwner()`,
 * which takes a pipeline for a chain); an operator, a ternary branch, a link of a chain and the cases of a
 * switch step in as the options say, an operator and a branch never by less than a level where the expression
 * shares its line with what holds it or begins its statement. A comment on a line of its own stands with
 * the line below it, above a closing bracket with the content it closes. The content of strings, heredocs
 * and inline HTML is text and never changes. Where each line goes is `Analyses\IndentationPlan`.
 */
#[RuleInfo(
	Stage::Finishing,
	modifiesComments: true,
	decisions: [
		IndentationPlan::Unit,
		'indentation.tabWidth',
		IndentationPlan::Binary,
		IndentationPlan::Ternary,
		IndentationPlan::TernaryBelowCondition,
		IndentationPlan::SwitchCase,
		IndentationPlan::Chain,
	],
	analyses: [IndentationPlan::class],
)]
final class IndentationRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [Nodes\FileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Nodes\FileNode) {
			return;
		}

		foreach ($context->getAnalysis(IndentationPlan::class)->getPlacements() as [$token, $indentation, $commentIndentation, $subject, $follows, $decision]) {
			$this->indent($token, $indentation, $commentIndentation, $subject, $context, $follows, $decision);
		}
	}


	private function indent(
		Token $token,
		string $indentation,
		string $commentIndentation,
		string $subject,
		RuleContext $context,
		?Token $follows,
		string $decision,
	): void
	{
		if (Indentation::matches($token, $indentation, $commentIndentation)) {
			return;
		}

		[$subject, $expected, $found] = $token->getIndentation() === $indentation
			? ['the comment', $commentIndentation, self::findCommentIndentation($token, $commentIndentation)]
			: [(string) preg_replace('~^an? ~', 'the ', $subject), $indentation, $token->getIndentation()];
		$message = match (true) {
			$expected === $found => 'Expected the inner lines of the comment aligned with its first line.',
			$expected === '' => "Expected $subject without indentation, " . NodeHelpers::describeWidth($found) . ' found.',
			default => "Expected $subject indented by " . NodeHelpers::describeWidth($expected) . ', ' . NodeHelpers::describeWidth($found) . ' found.',
		};
		if ($context->report($token, $message, decision: $decision, trivia: Indentation::findTrivia($token), follows: $follows)) {
			Indentation::set($token, $indentation, $commentIndentation);
		}
	}


	/** The indentation of the first comment on a line of its own above the token that does not have the expected one. */
	private static function findCommentIndentation(Token $token, string $expected): string
	{
		$lineStart = true;
		$indentation = '';
		foreach ($token->leadingTrivia as $trivia) {
			if ($trivia->isLineEnding()) {
				[$lineStart, $indentation] = [true, ''];
			} elseif ($lineStart && $trivia->id === Trivia::Whitespace) {
				$indentation = $trivia->text;
			} elseif ($lineStart && $trivia->isComment() && $indentation !== $expected) {
				return $indentation;
			} else {
				$lineStart = false;
			}
		}

		return $token->getIndentation();
	}
}
