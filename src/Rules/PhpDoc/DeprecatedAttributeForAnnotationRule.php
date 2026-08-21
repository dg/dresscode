<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{DeprecatedTagValueNode, PhpDocTagNode};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode};
use PhpSyntax\Nodes\Statement\{ConstNode, FunctionNode, TraitNode};
use function count;


/**
 * `@deprecated` tells a reader and an IDE, `#[\Deprecated]` of PHP 8.4 tells PHP as well, so the annotation
 * becomes the attribute and leaves the doc comment. What stood after the tag is split the way the attribute
 * takes it: a version at the front becomes `since`, the rest `message`.
 *
 * A function, a method, a class constant and an enum case take it, and from PHP 8.5 a trait and a constant
 * declared alone by `const`. A declaration that carries the attribute
 * already keeps its annotation, the two saying the same thing in one place each.
 *
 * Every fix is risky, and that is the point of it: a call of the declaration begins to raise
 * E_USER_DEPRECATED, which the annotation never did, so a run that logs notices starts logging them.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, requires: ['php' => '>=8.4'], analyses: [PhpDoc::class])]
final class DeprecatedAttributeForAnnotationRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.phpdoc.deprecated', Domain::adopted(), '`#[\\Deprecated]` for `@deprecated`')];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionNode::class, MethodNode::class, ClassConstNode::class, EnumCaseNode::class, TraitNode::class, ConstNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionNode
			&& !$node instanceof MethodNode
			&& !$node instanceof ClassConstNode
			&& !$node instanceof EnumCaseNode
			&& !$node instanceof TraitNode
			&& !$node instanceof ConstNode
		) {
			return;
		}

		$docComment = $node->getDocComment();
		if (
			$docComment === null
			|| $docComment->inInterpolation
			|| (($node instanceof TraitNode || $node instanceof ConstNode) && version_compare($context->phpVersion, '8.5', '<'))
			|| ($node instanceof ConstNode && count($node->items) !== 1) // PHP takes no attribute on several at once
			|| AnnotationReplacement::has($node->attributes, 'Deprecated')
		) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$kept = [];
		$arguments = null;
		foreach ($tree->children as $child) {
			if (
				!$child instanceof PhpDocTagNode
				|| !$child->value instanceof DeprecatedTagValueNode
				|| $arguments !== null // a second annotation stays where it is
				|| !$context->report($node, 'The `@deprecated` annotation must be written as the `#[\Deprecated]` attribute.', trivia: $docComment, risk: Risk::BehaviorChanges, because: 'PHP then reports a deprecation at every use')
			) {
				$kept[] = $child;
				continue;
			}

			$arguments = self::readArguments($child->value->description);
		}

		if ($arguments === null) {
			return;
		}

		$tree->children = $kept;
		AnnotationReplacement::writeAttributes($node, $docComment, $tree, ["\\Deprecated$arguments"], $phpDoc, $context);
	}


	/** The arguments the attribute takes, written out of what stood after the tag: a version and a message. */
	private static function readArguments(string $description): string
	{
		$description = trim($description);
		$since = preg_match('~^(\d+(\.\d+)*)(?!\S)\s*(.*)$~sD', $description, $m) === 1 ? $m[1] : null;
		$message = trim($since === null ? $description : $m[3]);
		$arguments = array_filter([
			'message' => $message === '' ? null : $message,
			'since' => $since,
		]);
		$written = [];
		foreach ($arguments as $name => $value) {
			$written[] = "$name: " . var_export(preg_replace('~\s+~', ' ', $value), true);
		}

		return $written === [] ? '' : '(' . implode(', ', $written) . ')';
	}
}
