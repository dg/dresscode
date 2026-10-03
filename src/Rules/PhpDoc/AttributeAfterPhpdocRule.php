<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{AnonymousClassNode, ParameterNode, PlainNodeList};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, FunctionNode, InterfaceNode, TraitNode};
use function count;


/**
 * The doc comment comes first, the attributes after it, right before the declaration. A doc comment found
 * between the attributes and the declaration moves above the first attribute.
 */
#[RuleInfo(
	'dresscode/attributeAfterPhpdoc',
	Stage::Structure,
	description: 'Moves a doc comment written after the attributes above them',
	modifiesComments: true,
)]
final class AttributeAfterPhpdocRule extends NodeRule
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
			!$attributes instanceof PlainNodeList
			|| $attributes->isEmpty()
			|| ($first = $node->getFirstToken()) === null
			|| !$first->startsLine()
			|| ($after = $attributes->getLastToken()?->getNext()) === null
		) {
			return;
		}

		$docComment = null;
		foreach ($after->leadingTrivia as $trivia) {
			if ($trivia->is(Trivia::DocComment) && !$trivia->inInterpolation) {
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
		$indentation = $leading && $leading[count($leading) - 1]->is(Trivia::Whitespace) ? array_pop($leading) : null;
		$first->setLeadingTrivia([
			...$leading,
			...($indentation ? [new Trivia(Trivia::Whitespace, $indentation->text)] : []),
			$docComment,
			Trivia::fromText($context->style->lineEnding),
			...($indentation ? [$indentation] : []),
		]);
	}
}
