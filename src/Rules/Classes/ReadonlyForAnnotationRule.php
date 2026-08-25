<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\PhpDoc;
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocChildNode, PhpDocTagNode};
use PhpSyntax\{Node, Token, TokenKind, Trivia, TriviaKind};
use PhpSyntax\Nodes\{AnonymousClassNode, ModifiersNode, ParameterNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyItemNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\ClassNode;
use function in_array;


/**
 * `@readonly`, `@psalm-readonly` and `@phpstan-readonly` tell an analyzer, the `readonly` of PHP 8.1 tells PHP as
 * well, so the annotation becomes the keyword and leaves the doc comment: on a property from 8.1, on a class from
 * 8.2, where it takes the annotations of the properties with it.
 *
 * The keyword is written only where the file shows that PHP compiles it. A property must carry a type and no default
 * value, and be neither static nor hooked; and since a child redeclaring a readonly property as a plain one is a fatal
 * error, it must be private or stand in a class nothing extends, a final or an anonymous one. A class must be final
 * and extend nothing, a readonly parent not being told from another without the types, use no trait, whose
 * properties are elsewhere, allow no dynamic properties, and every property of it must be one the keyword could
 * stand on.
 *
 * Every fix is risky: an annotation only asks an analyzer to complain, while PHP throws an Error at a write the
 * annotation let through, a hydrator setting a public property or a clone changing one among them.
 */
#[RuleInfo(
	'dresscode/readonly-for-annotation',
	Stage::Structure,
	description: 'Replaces the @readonly annotation with the readonly keyword',
	modifiesComments: true,
	requires: ['php' => '>=8.1'],
	risky: true,
)]
final class ReadonlyForAnnotationRule extends NodeRule
{
	private const Tags = ['@readonly', '@psalm-readonly', '@phpstan-readonly'];


	public function getVisitedTypes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode && !$node instanceof AnonymousClassNode) {
			return;
		}

		if (
			$node instanceof ClassNode
			&& version_compare($context->getPhpVersion(), '8.2', '>=')
			&& $this->hasTag($node, $context)
			&& $this->canBeReadonlyClass($node)
			&& $context->report($node->classKeyword, 'The class the annotation marks as readonly must be declared readonly', trivia: $node->getDocComment())
		) {
			$this->removeTag($node, $context);
			foreach ($node->members as $member) {
				if ($member instanceof PropertyNode) {
					$this->removeTag($member, $context);
				}
			}

			self::appendReadonly($node->modifiers);
		}

		if ($node->modifiers->isReadonly()) {
			return;
		}

		$final = $node instanceof AnonymousClassNode || $node->modifiers->isFinal();
		foreach ($node->members as $member) {
			if (
				$member instanceof PropertyNode
				&& !$member->modifiers->isReadonly()
				&& self::canBeReadonlyProperty($member)
				// a visibility written out, because readonly does not go with var
				&& ($member->modifiers->isPrivate() || ($final && ($member->modifiers->isProtected() || $member->modifiers->has(TokenKind::Public))))
				&& $this->hasTag($member, $context)
				&& $context->report($member, 'The property the annotation marks as readonly must be declared readonly', trivia: $member->getDocComment())
			) {
				$this->removeTag($member, $context);
				self::appendReadonly($member->modifiers);
			}
		}
	}


	private function canBeReadonlyClass(ClassNode $class): bool
	{
		if (
			$class->modifiers->isReadonly()
			|| !$class->modifiers->isFinal()
			|| $class->extends !== null
			|| preg_match('~(^|\W)AllowDynamicProperties\b~i', $class->attributes->text) === 1
		) {
			return false;
		}

		foreach ($class->members as $member) {
			if (
				$member instanceof TraitUseNode
				|| ($member instanceof PropertyNode && !self::canBeReadonlyProperty($member))
				|| ($member instanceof MethodNode && $member->isConstructor() && array_any(
					$member->parameters->getItems(),
					fn(ParameterNode $parameter) => $parameter->isPromoted() && ($parameter->type === null || $parameter->hooks !== null),
				))
			) {
				return false;
			}
		}

		return true;
	}


	/** Whether the declaration takes the keyword as it is written: typed, without a default, neither static nor hooked. */
	private static function canBeReadonlyProperty(PropertyNode $property): bool
	{
		return $property->type !== null
			&& $property->hooks === null
			&& !$property->modifiers->isStatic()
			&& !array_any($property->items->getItems(), fn(PropertyItemNode $item) => $item->default !== null);
	}


	private function hasTag(Node $node, RuleContext $context): bool
	{
		$docComment = $node->getDocComment();
		return $docComment !== null
			&& !$docComment->inInterpolation
			&& array_any(
				$context->getAnalysis(PhpDoc::class)->parse($docComment)->children,
				fn(PhpDocChildNode $child) => $child instanceof PhpDocTagNode && in_array(strtolower($child->name), self::Tags, true),
			);
	}


	private function removeTag(Node $node, RuleContext $context): void
	{
		$docComment = $node->getDocComment();
		if ($docComment === null || !$this->hasTag($node, $context)) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$tree->children = array_values(array_filter(
			$tree->children,
			fn(PhpDocChildNode $child) => !$child instanceof PhpDocTagNode || !in_array(strtolower($child->name), self::Tags, true),
		));
		if (PhpDoc::isEmpty($tree)) {
			$node->removeDocComment();
		} else {
			$node->replaceDocComment($phpDoc->print($tree, $docComment));
		}
	}


	private static function appendReadonly(ModifiersNode $modifiers): void
	{
		$token = new Token(TokenKind::Readonly, 'readonly');
		$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		$modifiers->append($token);
	}
}
