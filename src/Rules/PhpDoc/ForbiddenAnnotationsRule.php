<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\{Node, Token};
use function count;


/**
 * Annotations from the configured list are removed from doc comments; a doc comment left with nothing
 * but whitespace goes away with them.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [PhpDoc::class])]
final class ForbiddenAnnotationsRule extends NodeRule
{
	/** @var array<string, true>  lowercased names with the @ */
	private array $annotations = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('phpdoc.forbiddenAnnotations', new Names, 'The annotations removed from doc comments, written with the `@`, such as `@author` and `@package`'),
		];
	}


	public function configure(Values $values): void
	{
		$this->annotations = [];
		foreach ($values->get('phpdoc.forbiddenAnnotations')->getNames() as $annotation) {
			$this->annotations[strtolower($annotation)] = true;
		}
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || $this->annotations === [] || !$node->hasComment()) {
			return;
		}

		foreach ($node->getDocComments() as $trivia) {
			$phpDoc = $context->getAnalysis(PhpDoc::class);
			$tree = $phpDoc->parse($trivia);
			$kept = [];
			$fix = true;
			foreach ($tree->children as $child) {
				if ($child instanceof PhpDocTagNode && isset($this->annotations[strtolower($child->name)])) {
					$fix = $context->report($node, "Annotation `$child->name` is forbidden.", trivia: $trivia) && $fix;
				} else {
					$kept[] = $child;
				}
			}

			if (count($kept) === count($tree->children) || !$fix) {
				continue;
			}

			$tree->children = $kept;
			$phpDoc->writeBack($tree, $trivia, $node);
		}
	}
}
