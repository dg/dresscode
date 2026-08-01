<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\EnumCaseNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * The doc comment comes first, the attributes after it, right before the declaration. A doc comment found
 * between the attributes and the declaration moves above the first attribute.
 */
#[RuleInfo(
	'dresscode/attribute-after-phpdoc',
	Stage::Structure,
	description: 'Moves a doc comment written after the attributes above them',
	modifiesComments: true,
)]
final class AttributeAfterPhpDocRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [
			FunctionNode::class, MethodNode::class, ClosureNode::class, ArrowFunctionNode::class,
			ClassNode::class, InterfaceNode::class, TraitNode::class, EnumNode::class, AnonymousClassNode::class,
			PropertyNode::class, ClassConstNode::class, EnumCaseNode::class, ParameterNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$attributes = $node instanceof Token ? null : ($node->attributes ?? null);
		if (
			!$attributes instanceof NodeList
			|| $attributes->isEmpty()
			|| ($first = $node->getFirstToken()) === null
			|| !$first->startsLine()
			|| ($after = $attributes->getLastToken()?->getNext()) === null
		) {
			return;
		}

		$docComment = null;
		foreach ($after->leadingTrivia as $trivia) {
			if ($trivia->kind === TriviaKind::DocComment && !$trivia->inInterpolation) {
				$docComment = $trivia;
			}
		}

		if (
			$docComment === null
			|| !$context->report($node, 'The doc comment must be above the attributes', trivia: $docComment)
		) {
			return;
		}

		$after->removeTrivia($docComment);
		$leading = $first->leadingTrivia;
		$indentation = $leading && $leading[count($leading) - 1]->kind === TriviaKind::Whitespace ? array_pop($leading) : null;
		$first->setLeadingTrivia([
			...$leading,
			...($indentation ? [new Trivia(TriviaKind::Whitespace, $indentation->text)] : []),
			$docComment,
			new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol),
			...($indentation ? [$indentation] : []),
		]);
	}
}
