<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NativeType;
use PHPStan\PhpDocParser\Ast\PhpDoc\{ParamTagValueNode, PhpDocTagNode, PhpDocTextNode, ReturnTagValueNode, TypelessParamTagValueNode};
use PHPStan\PhpDocParser\Ast\Type\TypeNode as PhpDocTypeNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Type\{NamedTypeNode, NullableTypeNode};
use PhpSyntax\Nodes\TypeNode;


/**
 * A doc comment of a function that only repeats its signature, `@param int $a` on `int $a` and `@return void`
 * on `: void`, without a description of anything, is removed. An annotation of an array, iterable or traversable
 * type that says more than the native one (`int[]`, `array<string, Foo>`) keeps the doc comment.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	analyses: [PhpDoc::class, NameResolver::class],
)]
final class UselessFunctionPhpdocRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [
			new Decision('phpdoc.repeatingNativeTypes', Domain::state('forbidden'), 'A function doc comment that only repeats the native types of the signature, without a description of anything, is removed'),
		];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class, FunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof MethodNode && !$node instanceof FunctionNode)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$params = [];
		foreach ($node->parameters->getItems() as $param) {
			$params[$param->variable->name instanceof Token ? $param->variable->name->text : ''] = $param->type;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$tree = $context->getAnalysis(PhpDoc::class)->parse($docComment);
		if (PhpDoc::isEmpty($tree)) {
			return; // an empty doc comment is reported by noEmptyPhpdocs
		}

		foreach ($tree->children as $child) {
			if ($child instanceof PhpDocTextNode) {
				if (trim($child->text) !== '') {
					return;
				}

				continue;

			} elseif (!$child instanceof PhpDocTagNode) {
				return;

			} elseif ($child->value instanceof ReturnTagValueNode) {
				$useless = $child->value->description === '' && $this->repeats($child->value->type, $node->returnType, $resolver);

			} elseif ($child->value instanceof TypelessParamTagValueNode) {
				$useless = $child->value->description === '' && ($params[$child->value->parameterName] ?? null) !== null;

			} elseif ($child->value instanceof ParamTagValueNode) {
				$useless = $child->value->description === ''
					&& $this->repeats($child->value->type, $params[$child->value->parameterName] ?? null, $resolver);

			} else {
				return;
			}

			if (!$useless) {
				return;
			}
		}

		if ($context->report($node, 'Useless doc comment, because it only repeats the signature.', trivia: $docComment)) {
			$node->removeDocComment();
		}
	}


	/** Whether the annotation says exactly what the native type says. */
	private function repeats(PhpDocTypeNode $annotation, ?TypeNode $native, NameResolver $resolver): bool
	{
		if ($native === null) {
			return false;
		}

		$bare = $native instanceof NullableTypeNode ? $native->type : $native;
		if ($bare instanceof NamedTypeNode) {
			$traversable = NativeType::isTraversable($bare->name->text, ['traversable'], fn() => $resolver->resolveClass($bare->name));
			if ($traversable && !NativeType::isPlainIterable($annotation)) {
				return false;
			}
		}

		return NativeType::matches($annotation, $native->text);
	}
}
