<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocTagNode, PhpDocTextNode};
use PhpSyntax\{Indentation, Node, Token, Trivia};
use PhpSyntax\Nodes\Member\PropertyNode;
use function count;


/**
 * A property doc comment with a single annotation and no description is written on one line, unless that line would
 * be longer than the limit, or spread over three.
 */
#[RuleInfo(Stage::Formatting, modifiesComments: true, analyses: [PhpDoc::class])]
final class PropertyPhpdocLineSpanRule extends NodeRule
{
	private const LineSpan = 'phpdoc.property.lineSpan';

	private bool $expanded = false;


	public static function getDecisions(): array
	{
		return [new Decision(
			self::LineSpan,
			new Words([
				'collapsed' => 'on one line, `/** @var int */`',
				'expanded' => 'over three lines, the annotation on the one in the middle',
			]),
			'A property doc comment with a single annotation and no description',
		)];
	}


	public function configure(Values $values): void
	{
		$this->expanded = $values->find(self::LineSpan)?->getWord() === 'expanded';
	}


	public function getVisitedNodes(): array
	{
		return [PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof PropertyNode
			|| ($docComment = $node->getDocComment()) === null
			|| str_contains($docComment->text, "\n") === $this->expanded
		) {
			return;
		}

		$lines = [];
		foreach (explode("\n", $docComment->getCommentText()) as $line) {
			$line = trim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		if (count($lines) !== 1) {
			return;
		}

		$tree = $context->getAnalysis(PhpDoc::class)->parse($docComment);
		foreach ($tree->children as $child) {
			if ($child instanceof PhpDocTextNode && trim($child->text) !== '') {
				return;
			}
		}

		$tags = array_filter($tree->children, fn($child) => $child instanceof PhpDocTagNode);
		$indentation = $node->getFirstToken()?->getLineIndentation() ?? '';
		if ($this->expanded) {
			$eol = $context->style->lineEnding;
			$text = "/**$eol$indentation * $lines[0]$eol$indentation */";
			$message = 'The doc comment with a single annotation must be spread over three lines.';
		} else {
			$text = "/** $lines[0] */";
			$message = 'The doc comment with a single annotation must be written on one line.';
			$limit = $context->style->maxLineLength;
			if ($limit !== null && Indentation::advance(0, $indentation . $text, $context->style->toPhpSyntax()) > $limit) {
				return;
			}
		}

		if (count($tags) !== 1 || !$context->report($node, $message, trivia: $docComment)) {
			return;
		}

		$node->replaceDocComment(new Trivia(Trivia::DocComment, $text));
	}
}
