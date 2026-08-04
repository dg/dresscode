<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\Names;
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocTagNode, PhpDocTextNode};
use PhpSyntax\{Node, Token, Trivia};


/**
 * What one of the configured patterns matches in a line of the description of a doc comment is removed, the line
 * with it when nothing else is left ("Class constructor.", "Created by PhpStorm."); the description ends at the first
 * annotation, and a doc comment left empty goes away.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [PhpDoc::class])]
final class ForbiddenPhpdocLinesRule extends NodeRule
{
	/** @var list<string> */
	private array $patterns = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('phpdoc.forbiddenLines', new Names(regularExpressions: true), 'The patterns of the lines of a description removed from doc comments, such as `~^Created by~`, what a pattern matches going and the line with it where nothing else is left'),
		];
	}


	public function configure(Values $values): void
	{
		$this->patterns = $values->get('phpdoc.forbiddenLines')->getNames();
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || $this->patterns === [] || !$node->hasComment()) {
			return;
		}

		foreach ($node->getDocComments() as $trivia) {
			$this->processDocComment($node, $trivia, $context);
		}
	}


	private function processDocComment(Token $token, Trivia $trivia, RuleContext $context): void
	{
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($trivia);
		$prefix = preg_match('~\n([ \t]*\*)~', $trivia->text, $m) ? "\n$m[1] " : "\n";
		$changed = false;
		$fix = true;
		foreach ($tree->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				break; // only the description before the tags
			} elseif (!$child instanceof PhpDocTextNode) {
				continue;
			}

			// only a forbidden line changes, the blank lines and the indentation of the others staying as they are
			$lines = [];
			$hit = false;
			foreach (explode("\n", $child->text) as $line) {
				$matched = false;
				foreach ($this->patterns as $pattern) {
					if (preg_match($pattern, trim($line))) {
						$fix = $context->report($token, 'The doc comment line ' . Violation::formatCode(trim($line)) . ' is forbidden.', trivia: $trivia) && $fix;
						$line = trim((string) preg_replace($pattern, '', trim($line)));
						$matched = $hit = true;
					}
				}

				if (!$matched || $line !== '') {
					$lines[] = $line;
				}
			}

			if ($hit) {
				$text = (string) array_shift($lines);
				foreach ($lines as $line) {
					$text .= ($line === '' ? rtrim($prefix, ' ') : $prefix) . $line;
				}

				$child->text = $text;
				$changed = true;
			}
		}

		if (!$changed || !$fix) {
			return;
		}

		$children = $tree->children;
		while ($children && $children[0] instanceof PhpDocTextNode && trim($children[0]->text) === '') {
			array_shift($children);
		}

		$tree->children = $children;
		$phpDoc->writeBack($tree, $trivia, $token);
	}
}
