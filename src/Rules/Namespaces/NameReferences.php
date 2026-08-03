<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpDoc;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocAstNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, PhpDocTagNode, PhpDocTextNode};
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, SymbolKind, Trivia};
use PhpSyntax\Nodes\{FileNode, NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function in_array, is_array, is_string, strlen;


/**
 * What the rules of names share: the references of the names of a namespace with the form each is written in, the
 * class names its doc comments write and the imports.
 * @internal
 */
final class NameReferences
{
	/**
	 * The references of global names in the namespace and how each is written: imported, fully qualified, or bare, a
	 * function or a constant reached by the fallback at run time; by kind and name.
	 * @return array<string, array<string, list<array{NameNode, string, string}>>>  name of the kind => key of the global name => name, form and global name
	 */
	public static function collectGlobalUses(NamespaceNode $scope, NameResolver $resolver): array
	{
		$uses = [];
		foreach ($scope->find(NameNode::class) as $name) {
			if (!$name->isReference()) {
				continue;
			}

			$kind = $name->symbolKind;
			$global = $resolver->resolve($name);
			if (
				str_contains($global, '\\')
				|| ($kind === SymbolKind::Constant && in_array(strtolower($global), ['true', 'false', 'null'], true))
			) {
				continue;
			}

			// an unqualified class reaches the global namespace only through an import, a function or a constant also bare
			$form = match (true) {
				$name->form === NameForm::FullyQualified => QualificationPolicy::Backslashed,
				$name->form !== NameForm::Unqualified => null,
				$kind === SymbolKind::ClassLike, isset($resolver->getImports($kind, $name)[self::toKey($kind, $name->text)]) => QualificationPolicy::Imported,
				default => QualificationPolicy::Bare,
			};
			if ($form !== null) {
				$uses[$kind->name][self::toKey($kind, $global)][] = [$name, $form, $global];
			}
		}

		return $uses;
	}


	/**
	 * The imports of global names in the namespace block, by kind and name.
	 * @return array<string, array<string, list<array{UseNode, UseItemNode}>>>
	 */
	public static function collectGlobalImports(NamespaceNode $scope): array
	{
		$imports = [];
		foreach ($scope->statements->getItems() as $statement) {
			if (!$statement instanceof UseNode) {
				continue;
			}

			foreach ($statement->items->getItems() as $item) {
				if (!str_contains($item->fullName, '\\')) {
					$imports[$item->symbolKind->name][self::toKey($item->symbolKind, $item->fullName)][] = [$statement, $item];
				}
			}
		}

		return $imports;
	}


	/**
	 * What each unqualified class name of the namespace resolves to, and what the first part of each qualified name
	 * and of each class name its doc comments write does, all of which an import of a global class of that name would
	 * redirect.
	 * @return array<string, array<string, true>>  lowercased short name => lowercased resolved names
	 */
	public static function collectClassTargets(NamespaceNode $scope, NameResolver $resolver, PhpDoc $phpDoc): array
	{
		$targets = self::collectDocClassTargets($scope, $resolver, $phpDoc);
		foreach ($scope->find(NameNode::class) as $name) {
			if (!$name->isReference()) {
				continue;
			} elseif ($name->symbolKind === SymbolKind::ClassLike && $name->form === NameForm::Unqualified) {
				$targets[strtolower($name->text)][strtolower($resolver->resolveClass($name))] = true;
			} elseif ($name->form === NameForm::Qualified) {
				$targets[strtolower($name->parts[0])][strtolower(self::resolvePrefix($resolver, $name))] = true;
			}
		}

		return $targets;
	}


	/**
	 * What the first part of each class name the doc comments of the namespace write resolves to, which an import of
	 * that name would redirect as it would a name of the code.
	 * @return array<string, array<string, true>>  lowercased short name => lowercased resolved names
	 */
	public static function collectDocClassTargets(NamespaceNode $scope, NameResolver $resolver, PhpDoc $phpDoc): array
	{
		$targets = [];
		foreach (self::collectDocClassNames($scope, $phpDoc) as $docName) {
			$name = NameNode::tryFromText(explode('\\', $docName)[0]);
			if ($name !== null && !$name->isKeyword()) {
				$targets[strtolower($name->text)][strtolower($resolver->resolveClass($name, $scope))] = true;
			}
		}

		return $targets;
	}


	/**
	 * The class names the doc comments of the scope write the way an import reaches them: in a type, in a class
	 * constant, as the name of an annotation and as the target of a tag or of an inline tag.
	 * @return list<string>
	 */
	public static function collectDocClassNames(FileNode|NamespaceNode $scope, PhpDoc $phpDoc): array
	{
		$names = [];
		foreach ([...$scope->getLeadingComments(), ...$scope->getInnerComments(), ...$scope->getTrailingComments()] as $trivia) {
			if ($trivia->is(Trivia::DocComment)) {
				foreach (self::collectIdentifiers($phpDoc->parse($trivia)) as $identifier) {
					if ($identifier !== '' && $identifier[0] !== '\\' && $identifier[0] !== '$') {
						$names[] = $identifier;
					}
				}
			}
		}

		return $names;
	}


	/** @return list<string> */
	private static function collectIdentifiers(PhpDocAstNode $node): array
	{
		$result = [];
		if ($node instanceof IdentifierTypeNode) {
			$result[] = $node->name;
		} elseif ($node instanceof ConstFetchNode && $node->className !== '') {
			// `Shape::TYPE_*` as a type or a constant as the argument of an annotation
			$result[] = $node->className;
		} elseif ($node instanceof GenericTagValueNode) {
			// a tag the parser knows no type for, such as `@see Foo::bar()`; the target is the first word
			$result = self::findReferences($node->value, target: true);
		} elseif ($node instanceof PhpDocTextNode) {
			$result = self::findReferences($node->text, target: false);
		} elseif (
			($node instanceof PhpDocTagNode || $node instanceof DoctrineAnnotation)
			&& preg_match('~^@([A-Z]|.*\\\\)~', $node->name)
		) {
			// an annotation such as `@DB\Entity`, `@Endpoint` or `@Index` nested in another, unlike `@param` or `@phpstan-return`
			$result[] = substr($node->name, 1);
		}

		foreach (get_object_vars($node) as $property => $value) {
			if ($property === 'description' && is_string($value)) {
				// the description of a tag the parser knows, such as `@param int $x {@see Foo}`
				$result = [...$result, ...self::findReferences($value, target: false)];
			}

			foreach (is_array($value) ? $value : [$value] as $child) {
				if ($child instanceof PhpDocAstNode) {
					$result = [...$result, ...self::collectIdentifiers($child)];
				}
			}
		}

		return $result;
	}


	/**
	 * The names a piece of a doc comment the parser left as text refers to: the target of every inline tag
	 * (`{@link Foo}`) and, when the piece is the value of a tag, the word it starts with. A member behind
	 * `::` and the parentheses of a method are cut off, so that `Foo::bar()` counts as a use of `Foo`.
	 * @return list<string>
	 */
	private static function findReferences(string $text, bool $target): array
	{
		$name = '\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*';
		$result = [];
		if ($target && preg_match("~^$name~", $text, $m)) {
			$result[] = $m[0];
		}

		preg_match_all("~\\{@\\w+\\s+($name)~", $text, $matches);
		return [...$result, ...$matches[1]];
	}


	/** What the first part of a qualified name stands for, which the class imports decide whatever the name is of. */
	public static function resolvePrefix(NameResolver $resolver, NameNode $name): string
	{
		$resolved = $resolver->resolve($name);
		return substr($resolved, 0, strlen($resolved) - strlen($name->text) + strlen($name->parts[0]));
	}


	/** How a report names a symbol of the global namespace. */
	public static function describe(SymbolKind $kind, string $global): string
	{
		return match ($kind) {
			SymbolKind::Function => "Global function `$global()`",
			SymbolKind::Constant => "Global constant `$global`",
			SymbolKind::ClassLike => "Global class `$global`",
		};
	}


	/** The key a name is looked up by: a constant is case-sensitive, a class and a function are not. */
	public static function toKey(SymbolKind $kind, string $name): string
	{
		return $kind === SymbolKind::Constant ? $name : strtolower($name);
	}
}
