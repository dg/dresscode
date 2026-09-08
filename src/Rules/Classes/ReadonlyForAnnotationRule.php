<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\{PhpDoc, PhpSignatures, Types};
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocChildNode, PhpDocTagNode};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token, Visibility};
use PhpSyntax\Nodes\{AnonymousClassNode, ModifiersNode, ParameterNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyItemNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\ClassNode;


/**
 * `@readonly`, `@psalm-readonly` and `@phpstan-readonly` tell an analyzer, the `readonly` of PHP 8.1 tells PHP as
 * well, so the annotation becomes the keyword and leaves the doc comment: on a property from 8.1, on a class from
 * 8.2, where it takes the annotations of the properties with it.
 *
 * The keyword is written only where the file shows that PHP compiles it. A property must carry a type and no default
 * value, and be neither static nor hooked; from PHP 8.6 a default value is the initialization, so a property with one
 * takes the keyword where nothing in the class writes it. Since a child redeclaring a readonly property as a plain
 * one is a fatal error, it must be private or stand in a class nothing extends, a final or an anonymous one. A class
 * must be final and extend nothing, a readonly parent not being told from another without the types, use no trait,
 * whose properties are elsewhere, allow no dynamic properties, and every property of it must be one the keyword
 * could stand on.
 *
 * Every fix is risky: an annotation only asks an analyzer to complain, while PHP throws an Error at a write the
 * annotation let through, a hydrator setting a public property or a clone changing one among them.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	requires: ['php' => '>=8.1'],
	analyses: [PhpDoc::class, PhpSignatures::class, Types::class, NameResolver::class],
)]
final class ReadonlyForAnnotationRule extends NodeRule
{
	private const Tags = ['@readonly', '@psalm-readonly', '@phpstan-readonly'];


	public static function getDecisions(): array
	{
		return [new Decision('upgrading.phpdoc.readonly', Domain::adopted(), '`readonly` for `@readonly`')];
	}


	public function getVisitedNodes(): array
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
			&& version_compare($context->phpVersion, '8.2', '>=')
			&& $this->hasTag($node, $context)
			&& $this->canBeReadonlyClass($node, $context)
			&& $context->report(
				$node->classKeyword,
				"The class `{$node->name->token->text}` annotated as readonly must be declared readonly.",
				trivia: $node->getDocComment(),
				risk: Risk::BehaviorChanges,
				because: 'a write the annotation let through throws an Error',
			)
		) {
			$this->removeTag($node, $context);
			foreach ($node->members as $member) {
				if ($member instanceof PropertyNode) {
					$this->removeTag($member, $context);
				}
			}

			self::appendReadonly($node->modifiers);
		}

		if ($node->modifiers->readonly) {
			return;
		}

		$final = $node instanceof AnonymousClassNode || $node->modifiers->final;
		foreach ($node->members as $member) {
			if (
				$member instanceof PropertyNode
				&& !$member->modifiers->readonly
				&& self::canBeReadonlyProperty($member, $context->phpVersion)
				&& ($member->modifiers->visibility === Visibility::Private || $final)
				&& $this->hasTag($member, $context)
				&& self::isDefaultUnwritten($member, $node, $context)
				&& $context->report(
					$member,
					'The property `$' . $member->items->getItems()[0]->plainName . '` annotated as readonly must be declared readonly.',
					trivia: $member->getDocComment(),
					risk: Risk::BehaviorChanges,
					because: 'a write the annotation let through throws an Error',
				)
			) {
				$this->removeTag($member, $context);
				self::appendReadonly($member->modifiers);
			}
		}
	}


	private function canBeReadonlyClass(ClassNode $class, RuleContext $context): bool
	{
		if (
			$class->modifiers->readonly
			|| !$class->modifiers->final
			|| $class->extends !== null
			|| $context->getAnalysis(NameResolver::class)->hasAttribute($class, \AllowDynamicProperties::class)
		) {
			return false;
		}

		foreach ($class->members as $member) {
			if (
				$member instanceof TraitUseNode
				|| ($member instanceof PropertyNode && (!self::canBeReadonlyProperty($member, $context->phpVersion) || !self::isDefaultUnwritten($member, $class, $context)))
				|| ($member instanceof MethodNode && $member->isConstructor() && array_any(
					$member->parameters->getItems(),
					fn(ParameterNode $parameter) => $parameter->promoted && ($parameter->type === null || $parameter->hooks !== null),
				))
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Whether the declaration takes the keyword as it is written: typed, neither static nor hooked, and without a
	 * default before PHP 8.6.
	 */
	private static function canBeReadonlyProperty(PropertyNode $property, string $phpVersion): bool
	{
		return $property->type !== null
			&& $property->hooks === null
			&& !$property->modifiers->static
			&& (version_compare($phpVersion, '8.6', '>=') || !array_any($property->items->getItems(), fn(PropertyItemNode $item) => $item->default !== null));
	}


	/**
	 * Whether nothing in the class writes a property of the declaration that has a default value, the constructor
	 * included: readonly makes the default its initialization, and a write is then an Error, not a risk.
	 */
	private static function isDefaultUnwritten(PropertyNode $property, ClassNode|AnonymousClassNode $class, RuleContext $context): bool
	{
		return array_all(
			$property->items->getItems(),
			fn(PropertyItemNode $item) => $item->default === null || PropertyWrites::fromClass($class, $item->plainName, null, $context) !== null,
		);
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
		$phpDoc->writeBack($tree, $docComment, $node);
	}


	private static function appendReadonly(ModifiersNode $modifiers): void
	{
		if ($var = $modifiers->findToken(Token::Var)) { // readonly does not go with var
			$modifiers->removeToken($var);
			$modifiers->append(Token::fromText('public'));
		}

		$modifiers->append(Token::fromText('readonly'));
	}
}
