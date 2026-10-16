<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{PhpDoc, Types};
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\{CodeWriter, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AttributeNode, FileNode, NameNode};
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Type\{NamedTypeNode, UnionTypeNode};


/**
 * References of classes, interfaces and enums their declaration deprecates, wherever the name stands, an import,
 * a type, an instantiation, a static access, an attribute. Where the deprecation names the class to use instead,
 * `@deprecated use Acme\Mail\SmtpTransport`, and that class exists, the reference is rewritten to it and the imports
 * follow, a risky fix unless the deprecated class only stands for the replacement, extending or implementing it and
 * declaring nothing of its own; any other is reported with what the deprecation says. In a doc comment only a class the deprecation names
 * a replacement for is rewritten, one without it being left as it is. A reference inside a declaration deprecated
 * itself goes together with it and is left, and so is one in a union type that names the replacement too, which takes
 * both on purpose. A class the map of replacedClasses or of
 * forbiddenClasses has is not reported, nor an interface attributeForMember writes an attribute instead of, nor an
 * attribute attributeForAnnotation writes another one instead of.
 */
#[RuleInfo(
	Stage::Structure,
	typesRequired: true,
	modifiesComments: true,
	reads: [
		MemberMapGrammar::ReplacedClasses,
		MemberMapGrammar::ForbiddenClasses,
		MemberMapGrammar::AttributeForMember,
		MemberMapGrammar::AttributeForAnnotation,
	],
	analyses: [PhpDoc::class, Types::class, NameResolver::class],
)]
final class NoDeprecatedClassesRule extends NodeRule
{
	private const Because = 'the deprecation names the replacement, not that it behaves alike';

	/** @var array<string, true>  lowercased class, fully qualified, that a map of the libraries has */
	private array $mapped = [];

	private AnnotationMap $annotations;


	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.declarations.deprecatedClass',
			Domain::state(),
			'A class whose declaration is `@deprecated`, replaced by the class the deprecation names, reported where it names none',
		)];
	}


	public function configure(Values $values): void
	{
		$this->mapped = [];
		foreach ([
			...array_keys($values->readMap(MemberMapGrammar::ReplacedClasses)),
			...array_keys($values->readMap(MemberMapGrammar::ForbiddenClasses)),
		] as $class) {
			$this->mapped[strtolower(ltrim((string) $class, '\\'))] = true;
		}

		foreach (AttributeForMemberEntry::fromValues($values) as $entry) {
			if ($entry->kind === AttributeForMemberKind::Interface) {
				$this->mapped[strtolower($entry->class)] = true;
			}
		}

		$this->annotations = AnnotationMap::fromValues($values);
	}


	public function getVisitedNodes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ((!$node instanceof FileNode && !$node instanceof NamespaceNode) || CodeWriter::findImportScope($node) !== $node) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$find = function (string $class) use ($types): ?array {
			$deprecation = isset($this->mapped[strtolower($class)]) ? null : $types->findClassDeprecation($class);
			return $deprecation === null
				? null
				: [
					"Class `$class` is deprecated" . $deprecation->formatDescription(),
					$replacement = $deprecation->replacementClass,
					$replacement === null || $types->isAlias($class, $replacement) ? null : self::Because,
				];
		};
		$resolver = $context->getAnalysis(NameResolver::class);
		ClassReplacement::rewriteReferences(
			$node,
			$context,
			$find,
			fn(string $class, ?array $member, Token $token, array $union) => match (true) {
				($found = $find($class)) === null || $found[1] === null => null,
				NodeHelpers::isInDeprecatedDeclaration($token, $context),
				in_array(strtolower($found[1]), $union, true) => false,
				default => $found,
			},
			fn(NameNode $name) => NodeHelpers::isInDeprecatedDeclaration($name, $context)
				|| self::isBesideReplacement($name, $find($resolver->resolveClass($name))[1] ?? null, $resolver)
				// an attribute the map writes another one instead of is the map's
				|| ($name->parent instanceof AttributeNode
					&& $this->annotations->findReplacedAttribute($resolver->resolveClass($name)) !== null),
			$this->annotations,
		);
	}


	/** Whether the name stands in a union type that names its replacement too, which takes both on purpose. */
	private static function isBesideReplacement(NameNode $name, ?string $replacement, NameResolver $resolver): bool
	{
		$type = $name->parent;
		$union = $type?->parent?->parent;
		return $replacement !== null
			&& $type instanceof NamedTypeNode
			&& $union instanceof UnionTypeNode
			&& array_any(
				$union->types->getItems(),
				fn(Node $item) => $item instanceof NamedTypeNode
					&& strcasecmp($resolver->resolveClass($item->name), $replacement) === 0,
			);
	}
}
