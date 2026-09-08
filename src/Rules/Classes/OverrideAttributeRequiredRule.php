<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\{Member, MemberKind, Types};
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Tristate};
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, Token};
use PhpSyntax\Nodes\{AttributeNode, ParameterNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\TraitNode;


/**
 * A member that overrides one of a parent class or an interface carries `#[\Override]`, which makes PHP check
 * that it still overrides something: a parent that renames or drops the member then turns a silent new member
 * into an error. PHP 8.3 checks a method, 8.5 a property, a promoted one included, and 8.6 a constant and an enum
 * case, which overrides a constant of an interface.
 *
 * What the member overrides the types of the code say, so the rule runs only where the configuration gives
 * them. A member implementing an abstract one is left alone, PHP refusing a class that misses it already, and
 * so are a constructor and a destructor, a child declaring them without regard for the parent, and a member of
 * a trait, which overrides whatever the class using it has. A declaration of several constants or properties is
 * left to be split first, and a promoted property written on the line of another parameter has no line of its
 * own for the attribute. A bare `#[Override]` in a namespace that declares no such class is the attribute of PHP
 * left without an import, and gets its backslash rather than a second attribute.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=8.3'],
	typesRequired: true,
	analyses: [Types::class, NameResolver::class],
)]
final class OverrideAttributeRequiredRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.classes.Override', Domain::adopted(), '`#[\\Override]` on a member overriding an inherited one')];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class, PropertyNode::class, ParameterNode::class, ClassConstNode::class, EnumCaseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			&& !$node instanceof PropertyNode
			&& !$node instanceof ParameterNode
			&& !$node instanceof ClassConstNode
			&& !$node instanceof EnumCaseNode
		) {
			return;
		}

		[$since, $noun, $name] = self::readDeclaration($node);
		$types = $context->getAnalysis(Types::class);
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			version_compare($context->phpVersion, $since, '<')
			|| ($node instanceof MethodNode && ($node->isConstructor() || $node->isDestructor()))
			|| ($node instanceof ParameterNode && (!$node->promoted || !$node->getFirstToken()->startsLine()))
			|| $node->findAncestor(TraitNode::class) !== null
			|| $resolver->hasAttribute($node, \Override::class)
			|| ($overridden = $types->findOverridden($node)) === null
			|| $types->isAbstract($overridden) !== Tristate::No
			|| !$context->report($name, "The $noun overriding `" . self::formatMember($overridden) . '` must be marked with `#[\Override]`.')
		) {
			return;
		}

		$unimported = self::findUnimported($node, $resolver, $types);
		$written = CodeWriter::writeClass(\Override::class, $node, $context);
		if ($unimported !== null) {
			$unimported->name->text = $written;
		} else {
			CodeWriter::addAttributes($node, [$written], $context);
		}
	}


	/**
	 * The attribute written as a bare `Override` in a namespace that declares no such class, which is the one of PHP
	 * left without an import.
	 */
	private static function findUnimported(
		MethodNode|PropertyNode|ParameterNode|ClassConstNode|EnumCaseNode $declaration,
		NameResolver $resolver,
		Types $types,
	): ?AttributeNode
	{
		foreach ($declaration->attributes->getItems() as $group) {
			foreach ($group->items->getItems() as $attribute) {
				if (
					$attribute->name->form === NameForm::Unqualified
					&& strcasecmp($attribute->name->text, 'Override') === 0
					&& $types->findClassName($resolver->resolveClass($attribute->name)) === null
				) {
					return $attribute;
				}
			}
		}

		return null;
	}


	/**
	 * The version of PHP that checks the attribute on the declaration, the noun a message calls it by, and its name,
	 * the first one of a declaration of several, which overrides nothing the analysis would say.
	 * @return array{string, string, Node|Token}
	 */
	private static function readDeclaration(MethodNode|PropertyNode|ParameterNode|ClassConstNode|EnumCaseNode $declaration): array
	{
		return match (true) {
			$declaration instanceof MethodNode => ['8.3', 'method', $declaration->name],
			$declaration instanceof PropertyNode => ['8.5', 'property', $declaration->items->getItems()[0]->name],
			$declaration instanceof ParameterNode => ['8.5', 'property', $declaration->variable],
			$declaration instanceof ClassConstNode => ['8.6', 'constant', $declaration->items->getItems()[0]->name],
			default => ['8.6', 'enum case', $declaration->name],
		};
	}


	private static function formatMember(Member $member): string
	{
		return $member->declaringClass . '::' . match ($member->kind) {
			MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $member->name . '()',
			MemberKind::Property, MemberKind::StaticProperty => '$' . $member->name,
			MemberKind::Constant => $member->name,
		};
	}
}
