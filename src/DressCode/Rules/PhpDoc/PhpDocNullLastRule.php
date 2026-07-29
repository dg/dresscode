<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node as PhpDocParserNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;


/**
 * In a union type of a doc comment, `null` comes last: `int|string|null`, not `null|int|string`.
 */
#[RuleInfo(
	'dresscode/phpdoc-null-last',
	Stage::Structure,
	description: 'Moves null to the end of union types in doc comments',
	modifiesComments: true,
)]
final class PhpDocNullLastRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if ($trivia->kind !== TriviaKind::DocComment || $trivia->inInterpolation) {
				continue;
			}

			$phpDoc = $context->getAnalysis(PhpDoc::class);
			$tree = $phpDoc->parse($trivia);
			if (
				self::moveNullLast($tree)
				&& $context->report($node, 'null must come last in a union type in a doc comment', trivia: $trivia)
			) {
				$node->replaceTrivia($trivia, $phpDoc->print($tree, $trivia));
			}
		}
	}


	private static function moveNullLast(PhpDocParserNode $tree): bool
	{
		$visitor = new class extends AbstractNodeVisitor {
			public bool $changed = false;


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

					if ($nulls && $others && $node->types !== [...$others, ...$nulls]) {
						$this->changed = true;
						return new UnionTypeNode([...$others, ...$nulls]);
					}
				}

				return null;
			}
		};
		(new NodeTraverser([$visitor]))->traverse([$tree]);
		return $visitor->changed;
	}
}
