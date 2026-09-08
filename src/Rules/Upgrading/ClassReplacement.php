<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\RuleContext;
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\{NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\SymbolKind;
use function count, strlen;


/**
 * The rewrite replaced-classes makes: every reference of a class, interface or enum in a namespace
 * rewritten to the name that replaces it, wherever the name stands, an import, a type, an instantiation, a static
 * access, an attribute. An import of the old name is rewritten in place where its alias or its short name goes on
 * naming the class, else it goes and the references import the new name the way the scope imports.
 * @internal
 */
final class ClassReplacement
{
	/**
	 * Reports every reference of a replaced class and rewrites the ones the report allows.
	 * @param  array<string, string>  $classes  lowercased replaced name → the name written instead, both fully qualified
	 * @param  \Closure(string, string): string  $describe  the message, given the replaced and the replacing name
	 */
	public static function apply(NamespaceNode $scope, array $classes, RuleContext $context, \Closure $describe): void
	{
		if ($classes === []) {
			return;
		}

		// everything is found before anything is rewritten: a rewritten import changes what the names below it resolve to
		$resolver = $context->getAnalysis(NameResolver::class);
		$imports = $references = [];
		foreach ($scope->find(NameNode::class) as $name) {
			$item = $name->parent;
			if ($item instanceof UseItemNode) {
				$new = $item->kind === SymbolKind::ClassLike ? $classes[strtolower($item->fullName)] ?? null : null;
				if ($new !== null) {
					$imports[] = [$item, $item->fullName, $new];
				}
			} elseif ($name->role === SymbolKind::ClassLike && $name->isReference()) {
				$old = $resolver->resolveClass($name);
				$new = $classes[strtolower($old)] ?? null;
				if ($new !== null) {
					$references[] = [$name, $old, $new];
				}
			}
		}

		foreach ($imports as [$item, $old, $new]) {
			if ($context->report($item->name, $describe($old, $new))) {
				self::replaceImport($item, $new, $context);
			}
		}

		foreach ($references as [$name, $old, $new]) {
			if ($context->report($name, $describe($old, $new))) {
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
