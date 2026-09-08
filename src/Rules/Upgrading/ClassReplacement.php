<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\RuleContext;
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{FileNode, NameNode, SeparatedNodeList, UseItemNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, InterfaceNode, NamespaceNode};
use PhpSyntax\SymbolKind;
use function count, strlen;


/**
 * The rewrite replaced-classes makes: every reference of a class, interface or enum in a scope of imports
 * rewritten to the name that replaces it, wherever the name stands, an import, a type, an instantiation, a static
 * access, an attribute. An import of the old name is rewritten in place where its alias or its short name goes on
 * naming the class, and the fully qualified references of the new class in the scope are then written by that name;
 * else it goes and the references import the new name the way the scope imports. A name in the list of what a class
 * implements or an interface extends that the list names already, as it stands or once rewritten, goes from the list.
 * @internal
 */
final class ClassReplacement
{
	/**
	 * Reports every reference of a replaced class and rewrites the ones the report allows.
	 * @param  array<string, string>  $classes  lowercased replaced name → the name written instead, both fully qualified
	 * @param  \Closure(string, string): string  $describe  the message, given the replaced and the replacing name
	 */
	public static function apply(FileNode|NamespaceNode $scope, array $classes, RuleContext $context, \Closure $describe): void
	{
		if ($classes === []) {
			return;
		}

		// everything is found before anything is rewritten: a rewritten import changes what the names below it resolve to
		$resolver = $context->getAnalysis(NameResolver::class);
		$imports = $references = $lists = [];
		foreach ($scope->find(NameNode::class) as $name) {
			$item = $name->parent;
			if ($item instanceof UseItemNode) {
				$new = $item->kind === SymbolKind::ClassLike ? $classes[strtolower($item->fullName)] ?? null : null;
				if ($new !== null) {
					$imports[] = [$item, $item->fullName, $new];
				}

			} elseif ($name->role === SymbolKind::ClassLike && $name->isReference()) {
				$old = $resolver->resolveClass($name);
				$list = self::findInheritance($name);
				if ($list !== null) {
					$lists[spl_object_id($list)][strtolower($old)] = true;
				}

				$new = $classes[strtolower($old)] ?? null;
				if ($new !== null) {
					$references[] = [$name, $old, $new];
				}
			}
		}

		foreach ($imports as [$item, $old, $new]) {
			if ($context->report($item->name, $describe($old, $new))) {
				$local = self::replaceImport($item, $new, $context);
				if ($local !== null) {
					self::shortenFullyQualified($scope, $new, $local);
				}
			}
		}

		foreach ($references as [$name, $old, $new]) {
			$list = self::findInheritance($name);
			if (!$context->report($name, $describe($old, $new))) {
				continue;

			} elseif ($list !== null && isset($lists[spl_object_id($list)][strtolower($new)])) {
				// the list names the class already; what stood behind the last item stays behind the one before it
				$items = $list->getItems();
				if (end($items) === $name && count($items) > 1) {
					$items[count($items) - 2]->getLastToken()?->setTrailingTrivia($name->getLastToken()->trailingTrivia ?? []);
				}

				$list->removeItem($name);

			} else {
				$name->text = CodeWriter::spellClass($new, $name, $context, $name->isFullyQualified());
				if ($list !== null) {
					$lists[spl_object_id($list)][strtolower($new)] = true;
				}
			}
		}
	}


	/**
	 * The list of what a class or an enum implements, or of what an interface extends, that holds the name.
	 * @return ?SeparatedNodeList<NameNode>
	 */
	private static function findInheritance(NameNode $name): ?SeparatedNodeList
	{
		$list = $name->parent;
		$declaration = $list?->parent;
		return $list instanceof SeparatedNodeList && (
			(($declaration instanceof ClassNode || $declaration instanceof EnumNode) && $declaration->implements === $list)
			|| ($declaration instanceof InterfaceNode && $declaration->extends === $list)
		) ? $list : null;
	}


	/**
	 * The import stays where its alias or its short name goes on naming the class and, in a group, the prefix still
	 * names the namespace; else it goes and the references import the new name anew.
	 * @return ?string  the name the import stays under, null where it went
	 */
	private static function replaceImport(UseItemNode $item, string $new, RuleContext $context): ?string
	{
		$stmt = $item->getStatement();
		if ($stmt === null) {
			return null;
		}

		$prefix = $stmt->isGroup() ? ltrim($stmt->prefix->text, '\\') . '\\' : '';
		$short = self::shortName($new);
		if (
			str_starts_with(strtolower($new), strtolower($prefix))
			&& ($item->alias !== null
				|| strcasecmp($item->name->shortName, $short) === 0
				|| $context->getAnalysis(NameResolver::class)->isAliasFree($short, SymbolKind::ClassLike, $item))
		) {
			$item->name->text = substr($new, strlen($prefix));
			return $item->alias->text ?? $short;
		} elseif (count($stmt->items) === 1) {
			$stmt->remove();
		} else {
			$stmt->items->removeItem($item);
		}

		return null;
	}


	/**
	 * The fully qualified references of the class in the scope written by the name its import brings: a rule that
	 * wrote the class while the name still stood for the replaced one had to write it in full.
	 */
	private static function shortenFullyQualified(FileNode|NamespaceNode $scope, string $class, string $local): void
	{
		foreach ($scope->find(NameNode::class) as $name) {
			if (
				$name->role === SymbolKind::ClassLike
				&& $name->isReference()
				&& $name->isFullyQualified()
				&& strcasecmp(ltrim($name->text, '\\'), $class) === 0
			) {
				$name->text = $local;
			}
		}
	}


	private static function shortName(string $class): string
	{
		return substr($class, (int) strrpos('\\' . $class, '\\'));
	}
}
