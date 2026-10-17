<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NativeType;
use PHPStan\PhpDocParser\Ast\PhpDoc\{ParamTagValueNode, PhpDocTagNode, ReturnTagValueNode, TypelessParamTagValueNode, VarTagValueNode};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode};
use PhpSyntax\Nodes\{ParameterNode, TypeNode};
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * A `@param`, `@return` or `@var` without a description that names the very types the native type does goes, the
 * blank line separating it with it and the doc comment where nothing else remains: of a function, a method and
 * a property, a promoted one and a declaration of several included. A class name is compared as the imports resolve
 * it, and a traversable class of `types.traversableClasses` named bare repeats the native type as `array` does,
 * while an annotation saying what the items are says more. A doc comment with two annotations of one declaration is
 * left alone, and so is a constant, which `phpdoc.annotation.constantVar` decides.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [PhpDoc::class, NameResolver::class])]
final class UselessTypeAnnotationsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [
			new Decision(
				'phpdoc.annotation.nativeTypeOnly',
				Domain::state('forbidden'),
				'A `@param`, `@return` or `@var` without a description that says only the native type',
			),
		];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class, FunctionNode::class, PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof PropertyNode) {
			$name = $node->items->getItems()[0]->name->text;
			$this->removeTags($node, $node->type === null ? [] : ['' => [$node->type, "the property `$name`"]], null, $context);
			return;

		} elseif (!$node instanceof MethodNode && !$node instanceof FunctionNode) {
			return;
		}

		$params = [];
		foreach ($node->parameters->getItems() as $param) {
			$name = $param->variable->name;
			if ($name instanceof Token && $param->type !== null) {
				$params[$name->text] = [$param->type, "the parameter `$name->text`"];
				if ($param->promoted) { // a promoted property may carry its `@var` itself
					$this->removeTags($param, ['' => [$param->type, "the property `$name->text`"]], null, $context);
				}
			}
		}

		$this->removeTags($node, $params, $node->returnType, $context);
	}


	/**
	 * @param  array<string, array{TypeNode, string}>  $declared  the name of a parameter, `''` for a property => its
	 *   native type and how a message names the declaration
	 */
	private function removeTags(
		FunctionNode|MethodNode|PropertyNode|ParameterNode $node,
		array $declared,
		?TypeNode $returnType,
		RuleContext $context,
	): void
	{
		$docComment = $node->getDocComment();
		if ($docComment === null) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$resolver = $context->getAnalysis(NameResolver::class);
		$resolve = fn(string $class) => $resolver->resolveClass((new Builder)->name($class), $node);
		$seen = $useless = [];
		foreach ($tree->children as $child) {
			if (!$child instanceof PhpDocTagNode) {
				continue;
			}

			$value = $child->value;
			[$key, $native, $subject] = match (true) {
				$child->name === '@param' && ($value instanceof ParamTagValueNode || $value instanceof TypelessParamTagValueNode)
					=> [$value->parameterName, ...$declared[$value->parameterName] ?? [null, null]],
				$child->name === '@return' && $value instanceof ReturnTagValueNode => ['@return', $returnType, null],
				$child->name === '@var' && $value instanceof VarTagValueNode => ['@var', ...$declared[''] ?? [null, null]],
				default => [null, null, null],
			};
			if ($key === null) {
				continue;
			} elseif (isset($seen[$key])) {
				return; // two annotations of one declaration, which the project has to settle
			}

			$seen[$key] = true;
			$repeats = match (true) {
				$native === null => false,
				$value instanceof TypelessParamTagValueNode => $value->description === '',
				$value instanceof ParamTagValueNode, $value instanceof ReturnTagValueNode, $value instanceof VarTagValueNode
					=> $value->description === '' && NativeType::matches($value->type, $native->text, $resolve),
				default => false,
			};
			if ($repeats) {
				$useless[] = [$child, $subject];
			}
		}

		$removed = [];
		foreach ($useless as [$tag, $subject]) {
			if ($context->report(
				$node,
				$subject === null
					? "Useless `$tag->name` annotation, because the native return type says the same."
					: "Useless `$tag->name` annotation for $subject, because the native type says the same.",
				trivia: $docComment,
			)) {
				$removed[] = $tag;
			}
		}

		if ($removed !== []) {
			PhpDoc::keepChildren($tree, array_values(array_filter($tree->children, fn($child) => !in_array($child, $removed, true))));
			$phpDoc->writeBack($tree, $docComment, $node);
		}
	}
}
