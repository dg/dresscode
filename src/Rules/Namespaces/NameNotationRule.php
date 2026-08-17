<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use Nette\Schema\Context;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;
use function array_slice, count, strlen;


/**
 * A name referenced in a namespace is written imported (`use Foo\Bar;` and `Bar`) or with the leading backslash
 * (`\Foo\Bar`, `\strlen()`), the way the options say. The keys go from the general to the particular: `classes`,
 * `functions` and `constants` speak of every name of the kind, `globalClasses`, `globalFunctions` and `globalConstants`
 * of the names of the global namespace. A value is a shape, keep, or a map from a name or a pattern with `*` to one.
 * The most particular answer decides: a name given exactly, then a pattern by the length of what it spells out, then
 * the plain value of a key or `*`, and on a tie the more particular key. A name no key speaks of stays, and so does
 * a qualified name, which is relative to the import of its prefix or to the namespace, and every name of a file
 * without a namespace, where no-leading-backslash-in-global-namespace decides.
 *
 * Both shapes reach the same symbol, so no fix is risky. A bare global function or constant, reached by the fallback
 * of PHP at run time, is name-fallback's: this rule neither qualifies it nor adds an import that would take it over, and
 * leaves a name name-fallback writes bare alone. An import is not added where its name is taken; one the markup of the
 * file leaves no line for, or one a bare name would be taken over by unless name-fallback qualifies it, is reported to be
 * written by hand. A global function or
 * constant written with the leading backslash loses the import nothing uses any more; the import of a class is left to
 * unused-imports, which reads doc comments.
 */
#[RuleInfo(
	'dresscode/name-notation',
	Stage::Structure,
	description: 'Writes a referenced name imported or with the leading backslash',
)]
final class NameNotationRule extends NodeRule implements ConfigurableRule
{
	/** the keys that speak of a kind, the most particular first */
	private const Keys = [
		SymbolKind::ClassLike->name => ['globalClasses', 'classes'],
		SymbolKind::Function->name => ['globalFunctions', 'functions'],
		SymbolKind::Constant->name => ['globalConstants', 'constants'],
	];

	/** @var array<string, string|array<string, string>|null>  key → its value, null when not given */
	private array $options = [];


	public static function getOptionsSchema(): Schema
	{
		$shapes = [NameReferences::Import, NameReferences::Backslash];
		return Expect::structure([
			'classes' => NameReferences::expectOption($shapes)
				->description('Every class, interface, trait and enum: import, backslash, keep, or a map from a name or a pattern with * to one'),
			'globalClasses' => NameReferences::expectOption($shapes)
				->description('A class of the global namespace, written as the classes are'),
			'functions' => NameReferences::expectOption($shapes)
				->description('Every function, written as the classes are'),
			'globalFunctions' => NameReferences::expectOption($shapes)
				->description('A function of the global namespace once it is qualified; whether it stands bare decides name-fallback'),
			'constants' => NameReferences::expectOption($shapes)
				->description('Every constant, written as the classes are, a pattern matching its name case-sensitively'),
			'globalConstants' => NameReferences::expectOption($shapes)
				->description('A constant of the global namespace, written as the global functions are'),
		])->transform(function (mixed $options, Context $context): mixed {
			if (array_all((array) $options, fn($value) => $value === null)) {
				$context->addWarning('No key such as classes or globalFunctions is given, so every name stays as it is.', 'dresscode.noEffect');
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
		}
	}


	/**
	 * The shape a name is written in, import or backslash, given its fully qualified name without the leading backslash:
	 * every key of its kind speaks of a name of the global namespace, only the general one of any other; null when no key
	 * speaks of the name or the answer is keep.
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

		$this->fixGlobalNames($node, $context);
		$this->fixNamespacedNames($node, $context);
	}


	/** Writes the qualified names of the global namespace; a bare function or constant is name-fallback's. */
	private function fixGlobalNames(NamespaceNode $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$uses = NameReferences::collectGlobalUses($node, $resolver);
		$imports = NameReferences::collectGlobalImports($node);
		$classTargets = self::collectClassTargets($node, $resolver);
		$canImport = NodeHelpers::canAddImport($node);
		$fallback = $context->findRule(NameFallbackRule::class);

		foreach ([SymbolKind::ClassLike, SymbolKind::Function, SymbolKind::Constant] as $kind) {
			foreach ($uses[$kind->name] ?? [] as $key => $occurrences) {
				$global = $occurrences[0][2];
				$shape = $this->findShape($kind, $global);
				if ($shape === null || $fallback?->isWrittenBare($kind, $global, $node, $occurrences, $context)) {
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
				// the import would take over a bare name of the symbol, which is name-fallback's to decide: where that rule
				// qualifies it, the import is in place by the next pass
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
							NodeHelpers::addImport($node, $kind, $global, $context);
							$imported = true;
						}

						$name->text = $alias ?? $global;
					}
				}

				if ($kind === SymbolKind::ClassLike || $shape !== NameReferences::Backslash || $importStays) {
					continue;
				}

				foreach ($imports[$kind->name][$key] ?? [] as [$statement, $useItem]) {
					if ($context->report($useItem, "$subject must not be imported")) {
						count($statement->items) === 1 ? $statement->remove() : $statement->items->removeItem($useItem);
					}
				}
			}
		}
	}


	/** Writes the names of the namespaces other than the global one, imported or with the leading backslash. */
	private function fixNamespacedNames(NamespaceNode $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$canImport = NodeHelpers::canAddImport($node);
		$targets = self::collectShortNameTargets($node, $resolver);
		$imported = []; // name of the kind → key of the alias → the full name the rule imported under it
		foreach ($node->find(NameNode::class) as $name) {
			if ($name->isDeclaration() || $name->isKeyword() || $name->isSpecialClass()) {
				continue;
			}

			$kind = $name->role;
			$full = NameReferences::resolve($resolver, $name);
			$form = self::findNamespacedForm($resolver, $name, $full);
			$shape = $form === null ? null : $this->findShape($kind, $full);
			if ($shape === null || $form === $shape) {
				continue;
			} elseif ($shape === NameReferences::Backslash) {
				$written = '\\' . $full;
				if ($context->report($name, "The imported name {$name->text} must be written fully qualified as '$written'")) {
					$name->text = $written;
				}

				continue;
			}

			// an import the file has, or the bare name of the namespace of the file itself
			$alias = $name->shortName;
			$key = NameReferences::toKey($kind, $alias);
			$short = $resolver->getShortName($full, $kind, $name);
			$rule = $imported[$kind->name][$key] ?? null;
			if (!str_contains($short, '\\') || ($rule !== null && strcasecmp($rule, $full) === 0)) {
				if ($context->report($name, "The fully qualified name \\$full must be imported")) {
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
				$context->report($name, "The fully qualified name \\$full must be imported", fixable: false);
			} elseif ($context->report($name, "The fully qualified name \\$full must be imported")) {
				NodeHelpers::addImport($node, $kind, $full, $context);
				$imported[$kind->name][$key] = $full;
				$name->text = $alias;
			}
		}
	}


	/**
	 * What each unqualified class name of the namespace resolves to, and what the first part of each qualified name
	 * does, both of which an import of a global class of that name would redirect.
	 * @return array<string, array<string, true>>  lowercased short name → lowercased resolved names
	 */
	private static function collectClassTargets(NamespaceNode $scope, NameResolver $resolver): array
	{
		$targets = [];
		foreach ($scope->find(NameNode::class) as $name) {
			if ($name->isDeclaration() || $name->isKeyword() || $name->isSpecialClass()) {
				continue;
			} elseif ($name->role === SymbolKind::ClassLike && $name->kind === NameKind::Unqualified) {
				$targets[strtolower($name->text)][strtolower($resolver->resolveClass($name))] = true;
			} elseif ($name->kind === NameKind::Qualified) {
				$targets[strtolower($name->parts[0])][strtolower(self::resolvePrefix($resolver, $name))] = true;
			}
		}

		return $targets;
	}


	/** What the first part of a qualified name stands for, which the class imports decide whatever the name is of. */
	private static function resolvePrefix(NameResolver $resolver, NameNode $name): string
	{
		$resolved = NameReferences::resolve($resolver, $name);
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
		} elseif ($name->kind === NameKind::FullyQualified) {
			return NameReferences::Backslash;
		} elseif ($name->kind !== NameKind::Unqualified) {
			return null;
		}

		$target = NameReferences::getImports($resolver, $name->role, $name)[NameReferences::toKey($name->role, $name->shortName)] ?? null;
		return $target !== null && strcasecmp($target, $full) === 0 ? NameReferences::Import : null;
	}


	/**
	 * What each short name an import could take over resolves to in the scope, by kind: an unqualified name, which the
	 * import would redirect, and a fully qualified global class, a dropped leading backslash away from being one.
	 * @return array<string, array<string, array<string, true>>>  name of the kind → key of the alias → resolved names
	 */
	private static function collectShortNameTargets(NamespaceNode $scope, NameResolver $resolver): array
	{
		$targets = [];
		foreach ($scope->find(NameNode::class) as $name) {
			$kind = $name->role;
			$bare = $name->kind === NameKind::Unqualified
				|| ($kind === SymbolKind::ClassLike && $name->kind === NameKind::FullyQualified && count($name->parts) === 1);
			if ($name->isDeclaration() || $name->isKeyword() || $name->isSpecialClass()) {
				continue;
			} elseif ($name->kind === NameKind::Qualified) {
				// the first part of a qualified name is read through the class imports
				$targets[SymbolKind::ClassLike->name][strtolower($name->parts[0])][strtolower(self::resolvePrefix($resolver, $name))] = true;
				continue;
			} elseif (!$bare) {
				continue;
			}

			$targets[$kind->name][NameReferences::toKey($kind, $name->shortName)][strtolower(NameReferences::resolve($resolver, $name))] = true;
		}

		return $targets;
	}
}
