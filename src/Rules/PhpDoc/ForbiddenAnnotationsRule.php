<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Context, Expect, Schema};
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\{Node, Token, TriviaKind};
use function count;


/**
 * Annotations from the configured list are removed from doc comments; a doc comment left with nothing
 * but whitespace goes away with them.
 */
#[RuleInfo(
	'dresscode/forbidden-annotations',
	Stage::Structure,
	description: 'Removes the configured annotations from doc comments',
	modifiesComments: true,
)]
final class ForbiddenAnnotationsRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, true>  lowercased names with the @ */
	private array $annotations = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'annotations' => Expect::listOf('string')->description('Annotations to remove, written with the @'),
		])->transform(function (mixed $options, Context $context): mixed {
			if (((array) $options)['annotations'] === []) {
				$context->addWarning('No annotation is given, so nothing is removed.', 'dresscode.noEffect');
			}

			return $options;
		});
	}


	public function configure(array $options): void
	{
		$this->annotations = [];
		foreach ($options['annotations'] as $annotation) {
			$this->annotations[strtolower($annotation)] = true;
		}
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || $this->annotations === []) {
			return;
		}

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if ($trivia->kind !== TriviaKind::DocComment || $trivia->inInterpolation) {
				continue;
			}

			$phpDoc = $context->getAnalysis(PhpDoc::class);
			$tree = $phpDoc->parse($trivia);
			$kept = [];
			$fix = true;
			foreach ($tree->children as $child) {
				if ($child instanceof PhpDocTagNode && isset($this->annotations[strtolower($child->name)])) {
					$fix = $context->report($node, "Annotation $child->name is forbidden", trivia: $trivia) && $fix;
				} else {
					$kept[] = $child;
				}
			}

			if (count($kept) === count($tree->children) || !$fix) {
				continue;
			}

			$tree->children = $kept;
			if (PhpDoc::isEmpty($tree)) {
				$node->removeTrivia($trivia);
			} else {
				$node->replaceTrivia($trivia, $phpDoc->print($tree, $trivia));
			}
		}
	}
}
