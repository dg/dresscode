<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use Nette\Schema\Elements\AnyOf;
use Nette\Schema\{Expect, Helpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameKind, Node, SymbolKind, Token};
use PhpSyntax\Nodes\{NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function in_array, is_array, is_string, strlen;


/**
 * What dresscode/name-notation and dresscode/name-fallback share: an option given by name or by pattern, and the
 * references of the global names of a namespace with the form each is written in.
 * @internal
 */
final class NameReferences
{
	public const
		Import = 'import',
		Backslash = 'backslash',
		Bare = 'bare',
		Keep = 'keep';


	/**
	 * The schema of an option: one of the values or keep, and with patterns also a map from a name or a pattern with `*`
	 * to either. A plain value is the pattern * of a map that replaces the map of the layer below, as a plain value does,
	 * and a map of the layer above merges with it key by key.
	 * @param  list<string>  $values
	 */
	public static function expectOption(array $values, bool $patterns = true): AnyOf
	{
		if (!$patterns) {
			return Expect::anyOf(null, self::Keep, ...$values)->default(null);
		}

		return Expect::anyOf(null, ...[self::Keep, ...$values, Expect::arrayOf(Expect::anyOf(self::Keep, ...$values), 'string')])
			->before(fn(mixed $value): mixed => $value === null || (is_array($value) && !array_is_list(array_diff_key($value, [Helpers::PreventMerging => true])))
				? $value
				: ['*' => $value, Helpers::PreventMerging => true])
			->transform(fn(?array $map) => $map !== null && array_keys($map) === ['*'] ? $map['*'] : $map)
			->default(null);
	}


	/**
	 * The option the values give the name, the value of the most particular key first: a name given exactly outranks a
	 * pattern, a pattern spelling out more outranks one spelling out less, the plain value of a key ranks as `*`, and a
	 * tie goes to the more particular key; null when no value speaks of the name.
	 * @param  list<string|array<string, string>|null>  $values
	 */
	public static function findOption(array $values, string $name, SymbolKind $kind): ?string
	{
		$best = null;
		$bestRank = -1;
		foreach ($values as $value) {
			foreach (is_string($value) ? ['*' => $value] : $value ?? [] as $pattern => $option) {
				$rank = self::rankPattern((string) $pattern, $name, $kind);
				if ($rank > $bestRank) {
					[$best, $bestRank] = [$option, $rank];
				}
			}
		}

		return $best;
	}


	/** How particular a pattern is for the name: the length of what it spells out, the most for no `*`, -1 when it does not match. */
	private static function rankPattern(string $pattern, string $name, SymbolKind $kind): int
	{
		$pattern = ltrim($pattern, '\\');
		$regexp = $kind === SymbolKind::Constant
			// the namespace of a constant is read in any letter case, its own name after the last backslash is not,
			// whichever part of the pattern a * lets match it
			? implode('', array_map(fn(string $char) => match (true) {
				$char === '*' => '.*',
				ctype_alpha($char) => '(?:(?![^\\\\]*$)(?i:' . $char . ')|' . $char . ')',
				default => preg_quote($char, '~'),
			}, str_split($pattern)))
			: '(?i:' . str_replace('\*', '.*', preg_quote($pattern, '~')) . ')';
		return match (true) {
			!preg_match('~^' . $regexp . '$~D', $name) => -1,
			!str_contains($pattern, '*') => PHP_INT_MAX,
			default => strlen(str_replace('*', '', $pattern)),
		};
	}


	/**
	 * The references of global names in the namespace and how each is written: imported, with the leading backslash, or
	 * bare, a function or a constant reached by the fallback at run time; by kind and name.
	 * @return array<string, array<string, list<array{NameNode, string, string}>>>  name of the kind → key of the global name → name, form and global name
	 */
	public static function collectGlobalUses(NamespaceNode $scope, NameResolver $resolver): array
	{
		$uses = [];
		foreach ($scope->find(NameNode::class) as $name) {
			if (!$name->isReference()) {
				continue;
			}

			$kind = $name->role;
			$global = self::resolve($resolver, $name);
			if (
				str_contains($global, '\\')
				|| ($kind === SymbolKind::Constant && in_array(strtolower($global), ['true', 'false', 'null'], true))
			) {
				continue;
			}

			// an unqualified class reaches the global namespace only through an import, a function or a constant also bare
			$form = match (true) {
				$name->kind === NameKind::FullyQualified => self::Backslash,
				$name->kind !== NameKind::Unqualified => null,
				$kind === SymbolKind::ClassLike, isset(self::getImports($resolver, $kind, $name)[self::toKey($kind, $name->text)]) => self::Import,
				default => self::Bare,
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
					$imports[$item->kind->name][self::toKey($item->kind, $item->fullName)][] = [$statement, $item];
				}
			}
		}

		return $imports;
	}


	/** How a report names a symbol of the global namespace. */
	public static function describe(SymbolKind $kind, string $global): string
	{
		return match ($kind) {
			SymbolKind::Function => "Global function $global()",
			SymbolKind::Constant => "Global constant $global",
			SymbolKind::ClassLike => "Global class $global",
		};
	}


	/** @return array<string, string> */
	public static function getImports(NameResolver $resolver, SymbolKind $kind, Node|Token $at): array
	{
		return match ($kind) {
			SymbolKind::ClassLike => $resolver->getClassImports($at),
			SymbolKind::Function => $resolver->getFunctionImports($at),
			SymbolKind::Constant => $resolver->getConstantImports($at),
		};
	}


	/** What the name stands for, without a leading backslash. */
	public static function resolve(NameResolver $resolver, NameNode $name): string
	{
		return match ($name->role) {
			SymbolKind::ClassLike => $resolver->resolveClass($name),
			SymbolKind::Function => $resolver->resolveFunction($name),
			SymbolKind::Constant => $resolver->resolveConstant($name),
		};
	}


	/** The key a name is looked up by: a constant is case-sensitive, a class and a function are not. */
	public static function toKey(SymbolKind $kind, string $name): string
	{
		return $kind === SymbolKind::Constant ? $name : strtolower($name);
	}
}
