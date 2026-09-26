<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpSymbols;
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Context, Expect, Schema};
use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols};
use PhpSyntax\{Node, SymbolKind, Token, TokenKind, UnqualifiedResolution};
use PhpSyntax\Nodes\{Expression, NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count;


/**
 * A global function or constant referenced in a namespace stands qualified or bare, the way the options say. A bare
 * name is reached by the fallback of PHP at run time, after a function or a constant of that name in the namespace,
 * which is also why the compiler optimizes a call or puts a constant in place only for a qualified name. The keys go
 * from the general to the particular and the most particular answer decides, as in dresscode/name-notation;
 * `optimizedFunctions` speaks of a call PHP optimizes with its arguments (`NodeHelpers::isOptimizedCall()`),
 * `optimizedConstants` of a constant PHP declares where the compiler computes with it: in a constant expression, as
 * a constant condition or as an operand of a logical operator, and not where it is only passed or compared with
 * a variable.
 *
 * A name is qualified in the shape dresscode/name-notation gives it, with an import where that rule gives none:
 * through an import of the symbol under another name where the file has one, else with an import of its own, and
 * with the leading backslash where the name is taken; an import dresscode/name-notation asks for and the markup of
 * the file leaves no line for is reported to be written by hand. A name is made bare only where the bare name reaches
 * the global symbol, and the import nothing needs any more goes with it. Every fix is risky unless the resolution of
 * names is certain, because the namespace may declare a function or a constant of that name elsewhere. A file without
 * a namespace has no fallback to decide.
 */
#[RuleInfo(
	'dresscode/name-fallback',
	Stage::Structure,
	description: 'Writes a global function or constant in a namespace qualified, or bare and reached by the fallback at run time',
)]
final class NameFallbackRule extends NodeRule implements ConfigurableRule
{
	public const
		Qualified = 'qualified',
		Fallback = 'fallback';

	/** the keys that speak of a kind, the most particular first */
	private const Keys = [
		SymbolKind::Function->name => ['optimizedFunctions', 'functions'],
		SymbolKind::Constant->name => ['optimizedConstants', 'constants'],
	];

	/** @var array<string, string|array<string, string>|null>  key => its value, null when not given */
	private array $options = [];


	public static function getOptionsSchema(): Schema
	{
		$values = [self::Qualified, self::Fallback];
		return Expect::structure([
			'functions' => NameReferences::expectOption($values)
				->description('Every global function: qualified, fallback, keep, or a map from a name or a pattern with * to one'),
			'optimizedFunctions' => NameReferences::expectOption($values, patterns: false)
				->description('A global function the compiler or opcache treats specially, where it is called with arguments PHP optimizes: qualified, fallback or keep'),
			'constants' => NameReferences::expectOption($values)
				->description('Every global constant, decided as the functions are, a pattern matching its name case-sensitively'),
			'optimizedConstants' => NameReferences::expectOption([self::Qualified], patterns: false)
				->description('A constant PHP declares where the compiler computes with it, in a constant expression or condition: qualified or keep'),
		])->transform(function (mixed $options, Context $context): mixed {
			if (array_all((array) $options, fn($value) => $value === null)) {
				$context->addWarning('No key such as functions or optimizedFunctions is given, so every name stays as it is.', 'dresscode.noEffect');
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
	 * Whether the global function or constant of the name stands qualified or bare; null when the options leave it as it is.
	 * @param  list<array{NameNode, string, string}>  $occurrences  the references of the name in its namespace, which tell whether the compiler computes with a constant
	 */
	public function findOption(SymbolKind $kind, string $name, array $occurrences, RuleContext $context): ?string
	{
		if ($kind === SymbolKind::ClassLike) {
			return null;
		}

		$symbols = $context->getAnalysis(PhpSymbols::class);
		$values = [];
		foreach (self::Keys[$kind->name] as $key) {
			$speaks = match ($key) {
				'optimizedFunctions' => $this->options[$key] !== null
					&& array_any($occurrences, fn(array $occurrence) => ($call = $occurrence[0]->parent) instanceof Expression\FunctionCallNode
						&& $call->name === $occurrence[0]
						&& NodeHelpers::isOptimizedCall($call, $name, $context)),
				'optimizedConstants' => $this->options[$key] !== null
					&& $symbols->isInternalConstant($name)
					&& array_any($occurrences, fn(array $occurrence) => self::isComputed($occurrence[0], $context)),
				default => true,
			};
			$values[] = $speaks ? $this->options[$key] : null;
		}

		$option = NameReferences::findOption($values, $name, $kind);
		return $option === NameReferences::Keep ? null : $option;
	}


	/**
	 * Whether the rule writes the global function or constant bare in the scope: the options ask for it and the bare name reaches it.
	 * @param  list<array{NameNode, string, string}>  $occurrences
	 */
	public function isWrittenBare(
		SymbolKind $kind,
		string $global,
		NamespaceNode $scope,
		array $occurrences,
		RuleContext $context,
	): bool
	{
		return $this->findOption($kind, $global, $occurrences, $context) === self::Fallback
			&& self::canFallBack($context->getAnalysis(NameResolver::class), $context->getAnalysis(NamespacedSymbols::class), $scope, $kind, $global);
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

		$uses = NameReferences::collectGlobalUses($node, $context->getAnalysis(NameResolver::class));
		$imports = NameReferences::collectGlobalImports($node);
		foreach ([SymbolKind::Function, SymbolKind::Constant] as $kind) {
			foreach ($uses[$kind->name] ?? [] as $key => $occurrences) {
				$global = $occurrences[0][2];
				$option = $this->findOption($kind, $global, $occurrences, $context);
				if ($option === self::Qualified) {
					$this->qualify($node, $kind, $global, $occurrences, $imports[$kind->name][$key] ?? [], $context);
				} elseif ($option === self::Fallback) {
					$this->fallBack($node, $kind, $global, $occurrences, $imports[$kind->name][$key] ?? [], $context);
				}
			}
		}
	}


	/**
	 * Qualifies the bare names of the global symbol in the shape dresscode/name-notation gives, an import where it gives
	 * none: through an import of the symbol under another name, with an import of its own, which qualifies them all at
	 * once, or each with the leading backslash where the name is taken. An import dresscode/name-notation asks for and
	 * the markup leaves no line for is reported to be written by hand.
	 * @param  list<array{NameNode, string, string}>  $occurrences
	 * @param  list<array{UseNode, UseItemNode}>  $imports
	 */
	private function qualify(
		NamespaceNode $scope,
		SymbolKind $kind,
		string $global,
		array $occurrences,
		array $imports,
		RuleContext $context,
	): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$risky = !$context->getAnalysis(NamespacedSymbols::class)->complete;
		$subject = NameReferences::describe($kind, $global);
		$asked = $context->findRule(NameNotationRule::class)?->findShape($kind, $global);
		$item = $imports[0][1] ?? null;
		$alias = $item === null ? null : ($item->alias->text ?? $item->name->shortName);
		$canImport = NodeHelpers::canAddImport($scope);
		$shape = match (true) {
			$asked === NameReferences::Backslash => NameReferences::Backslash,
			$alias !== null => NameReferences::Import,
			!$canImport && $asked === NameReferences::Import => null,
			$canImport && !NameNode::fromText($global)->isKeyword() && $resolver->isAliasFree($global, $kind, $scope) => NameReferences::Import,
			default => NameReferences::Backslash,
		};

		foreach ($occurrences as [$name, $form]) {
			if ($form !== NameReferences::Bare) {
				continue;

			} elseif ($shape === null) {
				$context->report($name, "$subject must be imported", fixable: false);

			} elseif ($shape === NameReferences::Backslash) {
				if ($context->report($name, "$subject must be written with the leading backslash", risky: $risky)) {
					$name->text = '\\' . $global;
				}

				continue;

			} elseif ($alias !== null) {
				if ($context->report($name, "$subject must be imported", risky: $risky)) {
					$name->text = $alias;
				}

				continue;

			} elseif ($context->report($name, "$subject must be imported", risky: $risky)) {
				NodeHelpers::addImport($scope, $kind, $global, $context);
				return;
			}

			// one report stands for every name the import would qualify, a report silenced on its own line does not
			if (!$context->isSilenced($name)) {
				return;
			}
		}
	}


	/**
	 * Writes the qualified names of the global symbol bare where the bare name reaches it, and drops its import once no
	 * name needs it.
	 * @param  list<array{NameNode, string, string}>  $occurrences
	 * @param  list<array{UseNode, UseItemNode}>  $imports
	 */
	private function fallBack(
		NamespaceNode $scope,
		SymbolKind $kind,
		string $global,
		array $occurrences,
		array $imports,
		RuleContext $context,
	): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$namespaced = $context->getAnalysis(NamespacedSymbols::class);
		if (!self::canFallBack($resolver, $namespaced, $scope, $kind, $global)) {
			return;
		}

		$subject = NameReferences::describe($kind, $global);
		$importStays = false;
		foreach ($occurrences as [$name, $form]) {
			// the alias spelling the global name becomes bare by the removal of the import alone
			if (
				$form === NameReferences::Bare
				|| ($form === NameReferences::Import && strcasecmp($name->text, $global) === 0)
			) {
				continue;
			}

			$message = $form === NameReferences::Import ? "$subject must be written without the import" : "$subject must be written without the leading backslash";
			if ($context->report($name, $message, risky: !$namespaced->complete)) {
				$name->text = $global;
			} else {
				$importStays = $importStays || $form === NameReferences::Import;
			}
		}

		foreach ($importStays ? [] : $imports as [$statement, $item]) {
			if ($context->report($item, "$subject must not be imported", risky: !$namespaced->complete)) {
				count($statement->items) === 1 ? $statement->remove() : $statement->items->removeItem($item);
			}
		}
	}


	/**
	 * Whether the bare name reaches the global function or constant once no import stands in the way: the namespace
	 * neither declares it in the file nor lists it, and an import of that name, if any, imports the global one.
	 */
	private static function canFallBack(
		NameResolver $resolver,
		NamespacedSymbols $namespaced,
		NamespaceNode $scope,
		SymbolKind $kind,
		string $global,
	): bool
	{
		$import = NameReferences::getImports($resolver, $kind, $scope)[NameReferences::toKey($kind, $global)] ?? null;
		if ($import === null) {
			return $resolver->getUnqualifiedResolution($global, $kind, $scope) !== UnqualifiedResolution::Namespaced;
		}

		// with the import of the name in place the name reaches the global one anyway, so what the bare name would reach without it is asked directly
		$namespace = $resolver->getNamespace($scope);
		return strcasecmp($import, $global) === 0
			&& $resolver->findDeclaration("$namespace\\$global", $kind) === null
			&& !($kind === SymbolKind::Function ? $namespaced->hasFunction("$namespace\\$global") : $namespaced->hasConstant("$namespace\\$global"));
	}


	/**
	 * Whether the compiler computes with the constant the name refers to: it stands in a constant expression, whose value
	 * is computed ahead, or it is a constant condition or a constant operand of a logical operator, which opcache prunes.
	 */
	private static function isComputed(NameNode $name, RuleContext $context): bool
	{
		$fetch = $name->parent;
		if (!$fetch instanceof Expression\ConstantFetchNode) {
			return false;
		}

		$top = $fetch;
		while (
			($parent = $top->parent) instanceof Expression\ParenthesizedNode
			|| (
				($parent instanceof Expression\BinaryOpNode || $parent instanceof Expression\UnaryOpNode || $parent instanceof Expression\CastNode)
				&& !self::isLogical($parent)
				&& NodeHelpers::isConstantExpression($parent, $context, unqualified: true)
			)
		) {
			$top = $parent;
		}

		if ($top !== $fetch) {
			return true;
		}

		$node = $top;
		$parent = $node->parent;
		$logical = false;
		while ($parent instanceof Expression\ParenthesizedNode || ($parent !== null && self::isLogical($parent))) {
			$logical = $logical || self::isLogical($parent);
			[$node, $parent] = [$parent, $parent->parent];
		}

		return $logical
			|| ($parent instanceof Expression\MatchNode && $parent->subject === $node)
			|| ($parent !== null && property_exists($parent, 'condition') && $parent->condition === $node);
	}


	private static function isLogical(Node $node): bool
	{
		return ($node instanceof Expression\BinaryOpNode && $node->operator->is(TokenKind::BooleanAnd, TokenKind::BooleanOr, TokenKind::LogicalAnd, TokenKind::LogicalOr, TokenKind::LogicalXor))
			|| ($node instanceof Expression\UnaryOpNode && $node->operator->is('!'));
	}
}
