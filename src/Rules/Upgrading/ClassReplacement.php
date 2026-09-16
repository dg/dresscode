<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\RuleContext;
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{FileNode, NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\SymbolKind;
use function count, strlen;


/**
 * What no-deprecated-classes and replaced-classes share: every reference of a class, interface or enum in a scope of
 * imports rewritten to the name that replaces it, wherever the name stands, an import, a type, an instantiation,
 * a static access, an attribute. An import of the old name is rewritten in place where its alias or its short name goes on
 * naming the class, else it goes and the references import the new name the way the scope imports.
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
		$imports = $references = $kept = [];
		foreach ($scope->find(NameNode::class) as $name) {
			$item = $name->parent;
			if ($item instanceof UseItemNode) {
				$found = $item->kind === SymbolKind::ClassLike ? $find($item->fullName) : null;
				if ($found !== null) {
					$imports[] = [$item, $item->fullName, ...$found];
				}
			} elseif ($name->role === SymbolKind::ClassLike && $name->isReference()) {
				$class = $resolver->resolveClass($name);
				$found = $find($class);
				if ($found === null) {
					continue;
				} elseif ($keeps !== null && $keeps($name)) {
					$kept[strtolower($class)] = true;
				} else {
					$references[] = [$name, ...$found];
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

		foreach ($references as [$name, $message, $new]) {
			if ($context->report($name, $message, fixable: $new !== null) && $new !== null) {
				$name->text = CodeWriter::spellClass($new, $name, $context, $name->isFullyQualified());
			}
		}
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
