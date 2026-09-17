<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\Types;
use DressCode\RuleContext;
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{FileNode, NameNode, SeparatedNodeList, UseItemNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, InterfaceNode, NamespaceNode};
use PhpSyntax\SymbolKind;
use function count, strlen;


/**
 * What no-deprecated-classes and replaced-classes share: every reference of a class, interface or enum in a scope of
 * imports rewritten to the name that replaces it, wherever the name stands, an import, a type, an instantiation,
 * a static access, an attribute. An import of the old name is rewritten in place where its alias or its short name goes on
 * naming the class, else it goes and the references import the new name the way the scope imports. A name in the list
 * of what a class implements or an interface extends that the list names already, as it stands or once rewritten, goes
 * from the list; one the types say cannot stand where it would, a class to implement or an interface to extend by a
 * class, is reported and left.
 * @internal
 */
final class ClassReplacement
{
	/**
	 * Reports every reference of a class the closure has something to say about, and rewrites the ones it names
	 * a replacement for and the report allows.
	 * @param  \Closure(string): ?array{string, ?string}  $find  given a fully qualified class, the message and the class written instead, null for none; null for a class that is left alone
	 * @param  ?\Closure(NameNode): bool  $keeps  whether the reference goes on naming the class, which nothing is said about and whose import stays with it
	 */
	public static function apply(FileNode|NamespaceNode $scope, RuleContext $context, \Closure $find, ?\Closure $keeps = null): void
	{
		// everything is found before anything is rewritten: a rewritten import changes what the names below it resolve to
		$resolver = $context->getAnalysis(NameResolver::class);
		$types = $context->findAnalysis(Types::class);
		$imports = $references = $kept = $lists = [];
		foreach ($scope->find(NameNode::class) as $name) {
			$item = $name->parent;
			if ($item instanceof UseItemNode) {
				$found = $item->kind === SymbolKind::ClassLike ? $find($item->fullName) : null;
				if ($found !== null) {
					$imports[] = [$item, $item->fullName, ...$found];
				}

			} elseif ($name->role === SymbolKind::ClassLike && $name->isReference()) {
				$class = $resolver->resolveClass($name);
				$list = self::findInheritance($name);
				if ($list !== null) {
					$lists[spl_object_id($list)][strtolower($class)] = true;
				}

				$found = $find($class);
				$refusal = $found === null || $found[1] === null ? null : self::findRefusal($name, $found[1], $types);
				if ($found === null) {
					continue;
				} elseif ($keeps !== null && $keeps($name)) {
					$kept[strtolower($class)] = true;
				} else {
					$kept += $refusal === null ? [] : [strtolower($class) => true]; // the reference left as it is needs its import
					$references[] = [$name, ...$found, $refusal];
				}
			}
		}

		// an annotation read as an attribute resolves through the import until it is one
		$kept += $context->findRule(AttributeForAnnotationRule::class)?->findAnnotatedClasses($scope, $context) ?? [];
		foreach ($imports as [$item, $class, $message, $new]) {
			if (isset($kept[strtolower($class)])) {
				continue; // the short name below goes on naming the class the import brings
			} elseif ($context->report($item->name, $message, fixable: $new !== null) && $new !== null) {
				self::replaceImport($item, $new, $context);
			}
		}

		foreach ($references as [$name, $message, $new, $refusal]) {
			$list = self::findInheritance($name);
			$named = $list === null || $new === null ? null : $lists[spl_object_id($list)] ?? [];
			if ($refusal !== null) {
				$context->report($name, $message . $refusal, fixable: false);

			} elseif (!$context->report($name, $message, fixable: $new !== null) || $new === null) {
				continue;

			} elseif ($list !== null && isset($named[strtolower($new)])) {
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


	/** Why the class cannot stand where the name does, as a clause of the message; null where it can or the types cannot tell. */
	private static function findRefusal(NameNode $name, string $new, ?Types $types): ?string
	{
		$isInterface = $types?->isInterface($new);
		return match (true) {
			$isInterface === false && self::findInheritance($name) !== null => ", but $new is a class, which is not implemented",
			$isInterface === true && $name->parent instanceof ClassNode && $name->parent->extends === $name => ", but $new is an interface, which a class does not extend",
			default => null,
		};
	}


	/**
	 * The import stays where its alias or its short name goes on naming the class and, in a group, the prefix still
	 * names the namespace; else it goes and the references import the new name anew.
	 */
	private static function replaceImport(UseItemNode $item, string $new, RuleContext $context): void
	{
		$stmt = $item->getStatement();
		if ($stmt === null) {
			return;
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
		} elseif (count($stmt->items) === 1) {
			$stmt->remove();
		} else {
			$stmt->items->removeItem($item);
		}
	}


	private static function shortName(string $class): string
	{
		return substr($class, (int) strrpos('\\' . $class, '\\'));
	}
}
