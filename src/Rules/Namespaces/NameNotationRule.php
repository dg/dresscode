<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\CodeWriter;
use Nette\Schema\{Context, Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, Token};
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use function array_slice, count, is_string, strlen;


/**
 * A name referenced in a namespace is written imported (`use Foo\Bar;` and `Bar`) or with the leading backslash
 * (`\Foo\Bar`, `\strlen()`), the way the options say. The keys go from the general to the particular, the `global*`
 * ones speaking of the names of the global namespace, and the most particular answer decides
 * (`NameReferences::findOption()`). A name no key speaks of stays, and so does a qualified name, which is relative to
 * the import of its prefix or to the namespace, and every name of a file without a namespace, where
 * dresscode/uselessBackslashInGlobalNamespace decides.
 *
 * Both shapes reach the same symbol, so no fix is risky. A bare global function or constant, reached by the fallback
 * of PHP at run time, is dresscode/nameFallback's: this rule neither qualifies it nor adds an import that would take
 * it over, and leaves a name dresscode/nameFallback writes bare alone. An import is not added where its name is
 * taken; one the markup of the file leaves no line for, or one a bare name would be taken over by unless
 * dresscode/nameFallback qualifies it, is reported to be written by hand. A global function or constant written with
 * the leading backslash loses the import nothing uses any more; the import of a class is left to
 * dresscode/unusedImports, which reads doc comments.
 */
#[RuleInfo(
	'dresscode/nameNotation',
	Stage::Structure,
	description: 'Writes a referenced name imported or with the leading backslash',
)]
final class NameNotationRule extends NodeRule implements ConfigurableRule
{
	/** the keys that speak of a kind, the most particular first */
	private const Keys = [
		SymbolKind::ClassLike->name => ['globalClass', 'class'],
		SymbolKind::Function->name => ['globalFunction', 'function'],
		SymbolKind::Constant->name => ['globalConstant', 'constant'],
	];

	/** @var array<string, string|array<string, string>|null>  key => its value, null when not given */
	private array $options = [];

	/** whether the keys give some name of the global namespace a shape */
	private bool $shapesGlobal = false;

	/** whether the keys give some name of another namespace a shape */
	private bool $shapesNamespaced = false;


	public static function getOptionsSchema(): Schema
	{
		$shapes = [NameReferences::Import, NameReferences::Backslash];
		return Expect::structure([
			'class' => NameReferences::expectOption($shapes)
				->description('Every class, interface, trait and enum: `import`, `backslash`, `keep`, or a map from a name or a pattern with `*` to one'),
			'globalClass' => NameReferences::expectOption($shapes)
				->description('A class of the global namespace, written as the classes are'),
			'function' => NameReferences::expectOption($shapes)
				->description('Every function, written as the classes are'),
			'globalFunction' => NameReferences::expectOption($shapes)
				->description('A function of the global namespace once it is qualified; whether it stands bare decides `nameFallback`'),
			'constant' => NameReferences::expectOption($shapes)
				->description('Every constant, written as the classes are, a pattern matching its name case-sensitively'),
			'globalConstant' => NameReferences::expectOption($shapes)
				->description('A constant of the global namespace, written as the global functions are'),
		])->transform(function (mixed $options, Context $context): mixed {
			if (array_all((array) $options, fn($value) => $value === null)) {
				$context->addWarning('No key such as class or globalFunction is given, so every name stays as it is.', 'dresscode.noEffect');
			}

			return $options;
		});
	}


	public function configure(array $options): void
	{
		foreach (self::Keys as $keys) {
			foreach ($keys as $key) {
				$this->options[$key] = $options[$key] ?? null;
			}

			$values = array_map(fn(string $key) => $this->options[$key], $keys);
			$this->shapesGlobal = $this->shapesGlobal || !self::keepsAll($values);
			$this->shapesNamespaced = $this->shapesNamespaced || !self::keepsAll(array_slice($values, -1));
		}
	}


	/**
	 * Whether `NameReferences::findOption()` answers keep or nothing for every name: no value but keep, or keep as the
	 * plain value of the most particular key given, which no plain value of a later key outranks.
	 * @param  list<string|array<string, string>|null>  $values
	 */
	private static function keepsAll(array $values): bool
	{
		$values = array_values(array_filter($values, fn($value) => $value !== null));
		return array_all($values, fn($value) => is_string($value) ? $value === NameReferences::Keep : array_all($value, fn(string $option) => $option === NameReferences::Keep))
			|| (($values[0] ?? null) === NameReferences::Keep && array_filter($values, is_array(...)) === []);
	}


	/**
	 * The shape a name is written in, import or backslash, given its fully qualified name without the leading backslash:
	 * every key of its kind speaks of a name of the global namespace, only the general one of any other; null when no key
	 * speaks of the name or the answer is keep.
	 * @internal
	 */
	public function findShape(SymbolKind $kind, string $name): ?string
	{
		$keys = self::Keys[$kind->name];
		$values = array_map(fn(string $key) => $this->options[$key], str_contains($name, '\\') ? array_slice($keys, -1) : $keys);
		$shape = NameReferences::findOption($values, $name, $kind);
		return $shape === NameReferences::Keep ? null : $shape;
	}


	public function getVisitedTypes(): array
	{
		return [NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof NamespaceNode || $node->name === null) {
			return;
		}

		if ($this->shapesGlobal) {
			$this->fixGlobalNames($node, $context);
		}

		if ($this->shapesNamespaced) {
			$this->fixNamespacedNames($node, $context);
		}
	}


	/**
	 * Writes the names of the global namespace imported or with the leading backslash; a bare function or constant is
	 * dresscode/nameFallback's.
	 */
	private function fixGlobalNames(NamespaceNode $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$uses = NameReferences::collectGlobalUses($node, $resolver);
		$imports = NameReferences::collectGlobalImports($node);
		$classTargets = self::collectClassTargets($node, $resolver);
		$canImport = CodeWriter::canAddImport($node);
		$fallback = $context->findRule(NameFallbackRule::class);

		foreach ([SymbolKind::ClassLike, SymbolKind::Function, SymbolKind::Constant] as $kind) {
			foreach ($uses[$kind->name] ?? [] as $key => $occurrences) {
				$global = $occurrences[0][2];
				$shape = $this->findShape($kind, $global);
				if (
					$shape === null
					// every occurrence is written as it should, and no import is to go: nothing to do
					|| (array_all($occurrences, fn(array $occurrence) => $occurrence[1] === NameReferences::Bare || $occurrence[1] === $shape)
						&& ($kind === SymbolKind::ClassLike || $shape !== NameReferences::Backslash || !isset($imports[$kind->name][$key])))
					|| $fallback?->isWrittenBare($kind, $global, $node, $occurrences, $context)
				) {
					continue;
				}

				$item = $imports[$kind->name][$key][0] ?? null;
				$alias = $item === null ? null : ($item[1]->alias->text ?? $item[1]->name->shortName);
				$subject = NameReferences::describe($kind, $global);
				$importFree = $alias !== null || (
					!NameNode::fromText($global)->isKeyword()
					&& $resolver->isAliasFree($global, $kind, $node)
					&& array_all(array_keys($classTargets[$key] ?? []), fn(string $target) => strcasecmp($target, $global) === 0)
				);
				// the import would take over a bare name of the symbol, which is dresscode/nameFallback's to decide: where
				// that rule qualifies it, the import is in place by the next pass
				$overBare = $alias === null && array_any($occurrences, fn(array $occurrence) => $occurrence[1] === NameReferences::Bare);
				$deferred = $overBare && $fallback?->findOption($kind, $global, $occurrences, $context) === NameFallbackRule::Qualified;
				$imported = $importReported = $importStays = false;
				foreach ($occurrences as [$name, $form]) {
					if ($form === NameReferences::Bare || $form === $shape) {
						continue;

					} elseif ($shape === NameReferences::Backslash) {
						if ($context->report($name, "$subject must be written with the leading backslash")) {
							$name->text = '\\' . $global;
						} else {
							$importStays = true;
						}

					} elseif (!$importFree || $importReported || $deferred) {
						continue;

					} elseif ($alias === null && (!$canImport || $overBare)) {
						// the markup the namespace opens with leaves no line for the import, or a bare name would be taken over by it
						$context->report($name, "$subject must be imported", fixable: false);
						$importReported = !$context->isSilenced($name);

					} elseif ($context->report($name, "$subject must be imported")) {
						if ($alias === null && !$imported) {
							CodeWriter::addImport($node, $kind, $global, $context);
							$imported = true;
						}

						$name->text = $alias ?? $global;
					}
				}

				if ($kind === SymbolKind::ClassLike || $shape !== NameReferences::Backslash || $importStays) {
					continue;
				}

				foreach ($imports[$kind->name][$key] ?? [] as [, $useItem]) {
					if ($context->report($useItem, "$subject must not be imported")) {
						$useItem->remove();
					}
				}
			}
		}
	}


	/** Writes the names of the namespaces other than the global one, imported or with the leading backslash. */
	private function fixNamespacedNames(NamespaceNode $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$canImport = CodeWriter::canAddImport($node);
		$targets = self::collectShortNameTargets($node, $resolver);
		$imported = []; // name of the kind => key of the alias => the full name the rule imported under it
		foreach ($node->find(NameNode::class) as $name) {
			// a qualified or relative name stays, and only the general key speaks of a namespaced one
			if (
				!$name->isReference()
				|| $name->form === NameForm::Qualified
				|| $name->form === NameForm::Relative
				|| $this->options[self::Keys[$name->symbolKind->name][1]] === null
			) {
				continue;
			}

			$kind = $name->symbolKind;
			$full = $resolver->resolve($name);
			$form = self::findNamespacedForm($resolver, $name, $full);
			$shape = $form === null ? null : $this->findShape($kind, $full);
			if ($shape === null || $form === $shape) {
				continue;

			} elseif ($shape === NameReferences::Backslash) {
				$written = '\\' . $full;
				if ($context->report($name, "The imported name `{$name->text}` must be written fully qualified as `$written`")) {
					$name->text = $written;
				}

				continue;
			}

			// an import the file has, or the bare name of the namespace of the file itself
			$alias = $name->shortName;
			$key = NameReferences::toKey($kind, $alias);
			$short = $resolver->shortenName($full, $kind, $name);
			$rule = $imported[$kind->name][$key] ?? null;
			if (!str_contains($short, '\\') || ($rule !== null && strcasecmp($rule, $full) === 0)) {
				if ($context->report($name, "The fully qualified name `\\$full` must be imported")) {
					$name->text = str_contains($short, '\\') ? $alias : $short;
				}
			} elseif (
				$rule !== null
				|| NameNode::fromText($alias)->isKeyword() // a keyword is no name a bare call or reference could stand on
				|| !$resolver->isAliasFree($alias, $kind, $name)
				|| array_any(array_keys($targets[$kind->name][$key] ?? []), fn(string $target) => strcasecmp($target, $full) !== 0)
			) {
				continue;
			} elseif (!$canImport) {
				// the markup the namespace opens with leaves no line for the import, which is still one to write by hand
				$context->report($name, "The fully qualified name `\\$full` must be imported", fixable: false);
			} elseif ($context->report($name, "The fully qualified name `\\$full` must be imported")) {
				CodeWriter::addImport($node, $kind, $full, $context);
				$imported[$kind->name][$key] = $full;
				$name->text = $alias;
			}
		}
	}


	/**
	 * What each unqualified class name of the namespace resolves to, and what the first part of each qualified name
	 * does, both of which an import of a global class of that name would redirect.
	 * @return array<string, array<string, true>>  lowercased short name => lowercased resolved names
	 */
	private static function collectClassTargets(NamespaceNode $scope, NameResolver $resolver): array
	{
		$targets = [];
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


	/** What the first part of a qualified name stands for, which the class imports decide whatever the name is of. */
	private static function resolvePrefix(NameResolver $resolver, NameNode $name): string
	{
		$resolved = $resolver->resolve($name);
		return substr($resolved, 0, strlen($resolved) - strlen($name->text) + strlen($name->parts[0]));
	}


	/**
	 * How a name of a namespace other than the global one is written: backslash when it is fully qualified, import
	 * when it is the alias of an import of the whole name; null for a global name and for a qualified one, which is
	 * relative.
	 */
	private static function findNamespacedForm(NameResolver $resolver, NameNode $name, string $full): ?string
	{
		if (!str_contains($full, '\\')) {
			return null;
		} elseif ($name->form === NameForm::FullyQualified) {
			return NameReferences::Backslash;
		} elseif ($name->form !== NameForm::Unqualified) {
			return null;
		}

		$target = $resolver->getImports($name->symbolKind, $name)[NameReferences::toKey($name->symbolKind, $name->shortName)] ?? null;
		return $target !== null && strcasecmp($target, $full) === 0 ? NameReferences::Import : null;
	}


	/**
	 * What each short name an import could take over resolves to in the scope, by kind: an unqualified name, which the
	 * import would redirect, and a fully qualified global class, a dropped leading backslash away from being one.
	 * @return array<string, array<string, array<string, true>>>  name of the kind => key of the alias => resolved names
	 */
	private static function collectShortNameTargets(NamespaceNode $scope, NameResolver $resolver): array
	{
		$targets = [];
		foreach ($scope->find(NameNode::class) as $name) {
			$kind = $name->symbolKind;
			$bare = $name->form === NameForm::Unqualified
				|| ($kind === SymbolKind::ClassLike && $name->form === NameForm::FullyQualified && count($name->parts) === 1);
			if (!$name->isReference()) {
				continue;
			} elseif ($name->form === NameForm::Qualified) {
				// the first part of a qualified name is read through the class imports
				$targets[SymbolKind::ClassLike->name][strtolower($name->parts[0])][strtolower(self::resolvePrefix($resolver, $name))] = true;
				continue;
			} elseif (!$bare) {
				continue;
			}

			$targets[$kind->name][NameReferences::toKey($kind, $name->shortName)][strtolower($resolver->resolve($name))] = true;
		}

		return $targets;
	}
}
