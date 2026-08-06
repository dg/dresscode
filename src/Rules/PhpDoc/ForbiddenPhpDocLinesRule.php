<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Context, Expect, Schema};
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocTagNode, PhpDocTextNode};
use PhpSyntax\{Node, Token, Trivia, TriviaKind};


/**
 * What one of the configured patterns matches in a line of the description of a doc comment is removed, the line
 * with it when nothing else is left ("Class constructor.", "Created by PhpStorm."); the description ends at the first
 * annotation, and a doc comment left empty goes away.
 */
#[RuleInfo(
	'dresscode/forbidden-phpdoc-lines',
	Stage::Structure,
	description: 'Removes description lines matching the configured patterns from doc comments',
	modifiesComments: true,
)]
final class ForbiddenPhpDocLinesRule extends NodeRule implements ConfigurableRule
{
	/** @var list<string> */
	private array $patterns = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'patterns' => Expect::listOf('string')->description('Regular expressions matched against every line of a description'),
		])->transform(function (mixed $options, Context $context): mixed {
			if (((array) $options)['patterns'] === []) {
				$context->addWarning('No pattern is given, so nothing is removed.', 'dresscode.noEffect');
			}

			return $options;
		});
	}


	public function configure(array $options): void
	{
		$this->patterns = $options['patterns'];
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || $this->patterns === []) {
			return;
		}

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if ($trivia->kind === TriviaKind::DocComment && !$trivia->inInterpolation) {
				$this->processDocComment($node, $trivia, $context);
			}
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

			$lines = [];
			foreach (explode("\n", $child->text) as $line) {
				$line = trim($line);
				foreach ($this->patterns as $pattern) {
					if (preg_match($pattern, $line)) {
						$fix = $context->report($token, "Forbidden doc comment line '$line'", trivia: $trivia) && $fix;
						$line = trim((string) preg_replace($pattern, '', $line));
						$changed = true;
					}
				}

				if ($line !== '') {
					$lines[] = $line;
				}
			}

			$child->text = implode($prefix, $lines);
		}

		if (!$changed || !$fix) {
			return;
		}

		$children = $tree->children;
		while ($children && $children[0] instanceof PhpDocTextNode && trim($children[0]->text) === '') {
			array_shift($children);
		}

		$tree->children = $children;
		if (PhpDoc::isEmpty($tree)) {
			$token->removeTrivia($trivia);
		} else {
			$token->replaceTrivia($trivia, $phpDoc->print($tree, $trivia));
		}
	}
}
