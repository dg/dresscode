<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PHPStan\PhpDocParser\Ast\{AbstractNodeVisitor, Node as PhpDocParserNode, NodeTraverser};
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\{IdentifierTypeNode, UnionTypeNode};
use PhpSyntax\{Node, Token, Trivia};


/**
 * In a union type of a doc comment, `null` stands at one end: last (`int|string|null`) or first (`null|int|string`).
 */
#[RuleInfo(
	'dresscode/phpdocNullPosition',
	Stage::Structure,
	description: 'Moves `null` to the chosen end of union types in doc comments',
	modifiesComments: true,
)]
final class PhpdocNullPositionRule extends NodeRule implements ConfigurableRule
{
	private bool $first = false;
	private string $message = '`null` must come last in a union type in a doc comment';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'position' => Expect::anyOf('last', 'first')->default('last')->description('Where `null` stands in a union type'),
		]);
	}


	public function configure(array $options): void
	{
		$this->first = $options['position'] === 'first';
		$this->message = "`null` must come {$options['position']} in a union type in a doc comment";
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->hasComment()) {
			return;
		}

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if ($trivia->id !== Trivia::DocComment || $trivia->inInterpolation) {
				continue;
			}

			$phpDoc = $context->getAnalysis(PhpDoc::class);
			$tree = $phpDoc->parse($trivia);
			if (
				self::moveNull($tree, $this->first)
				&& $context->report($node, $this->message, trivia: $trivia)
			) {
				$node->replaceTrivia($trivia, $phpDoc->print($tree, $trivia));
			}
		}
	}


	private static function moveNull(PhpDocParserNode $tree, bool $first): bool
	{
		$visitor = new class ($first) extends AbstractNodeVisitor {
			public bool $changed = false;


			public function __construct(
				private readonly bool $first,
			) {
			}


			/**
			 * A reordered union is a new node: the format-preserving printer prints a reordered list wrongly.
			 * @return PhpDocParserNode|NodeTraverser::DONT_TRAVERSE_CHILDREN|null
			 */
			public function enterNode(PhpDocParserNode $node): PhpDocParserNode|int|null
			{
				if ($node instanceof DoctrineTagValueNode) {
					return NodeTraverser::DONT_TRAVERSE_CHILDREN;
				}

				if ($node instanceof UnionTypeNode) {
					$others = $nulls = [];
					foreach ($node->types as $type) {
						if ($type instanceof IdentifierTypeNode && strtolower($type->name) === 'null') {
							$nulls[] = $type;
						} else {
							$others[] = $type;
						}
					}

					$ordered = $this->first ? [...$nulls, ...$others] : [...$others, ...$nulls];
					if ($nulls && $others && $node->types !== $ordered) {
						$this->changed = true;
						return new UnionTypeNode($ordered);
					}
				}

				return null;
			}
		};
		new NodeTraverser([$visitor])->traverse([$tree]);
		return $visitor->changed;
	}
}
