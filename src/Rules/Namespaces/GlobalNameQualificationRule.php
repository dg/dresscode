<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\{PhpDoc, PhpSymbols};
use DressCode\{NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\{CodeWriter, Compiler};
use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols};
use PhpSyntax\{Node, SymbolKind, Token, UnqualifiedResolution};
use PhpSyntax\Nodes\{ElseifNode, Expression, NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\{DoWhileNode, IfNode, NamespaceNode, UseNode, WhileNode};


/**
 * How far a name of the global namespace referenced in a namespace is written out: `imported`, `backslashed`, or
 * `bare`, which for a function or a constant is reached by the fallback of PHP at run time. A key takes a word, or a
 * list of them, every form in it passing and the first written where none matches. A function or a constant the
 * compiler works with is decided by the key of the optimized ones where that key requires a form, the arguments of
 * such a call being `OptimizedCallNotationRule`'s.
 *
 * The names are written symbol by symbol: an occurrence in none of the forms is written in the first form the symbol
 * can take. Bare is open only where the bare name reaches the global symbol, which the fallback does where the
 * namespace declares no function or constant of that name; imported goes through the import of the symbol the
 * namespace has, or through one added where its alias is free, the markup leaves a line for it and it takes over no
 * bare name the decisions take; fully qualified is always open. An added import changes every bare name of the symbol
 * at once, so it is added only where each of them agrees: none is silenced and every report is admitted. Where the
 * first form is imported and no form is open, an import the name is free for is reported once to be written by hand.
 * The import of a function or a constant goes once no name needs it, the import of a class being left to
 * `NoUnusedImportsRule`, which reads doc comments.
 *
 * A change between a bare and a qualified name is risky unless the resolution of names is certain, because the
 * namespace may declare a function or a constant of that name elsewhere.
 */
#[RuleInfo(
	Stage::Structure,
	decisions: ['qualification.globalFunction', 'qualification.optimizedFunction'],
	analyses: [PhpDoc::class, PhpSymbols::class, NameResolver::class, NamespacedSymbols::class],
)]
final class GlobalNameQualificationRule extends NodeRule
{
	private QualificationPolicy $policy;


	public static function getDecisions(): array
	{
		return [
			QualificationPolicy::createQualifiedDecision(
				'qualification.globalClass',
				'A class of the global namespace referenced in a namespace, `DateTime`, which a bare name there does not reach',
				'`use DateTime;` and `DateTime`',
				'`\DateTime`',
			),
			QualificationPolicy::createGlobalDecision(
				'qualification.globalConstant',
				'A global constant in a namespace, `PHP_EOL`, those the compiler computes with included unless `optimizedConstant` requires a form for them',
				'`PHP_EOL`',
				'`use const PHP_EOL;` and `PHP_EOL`',
				'`\PHP_EOL`',
			),
			QualificationPolicy::createOptimizedDecision(
				'qualification.optimizedConstant',
				'A constant of PHP the compiler computes with once it knows the constant is global, `PHP_VERSION_ID` in a condition, `PHP_INT_MAX` in a constant expression; where this key requires a form, it decides such a constant over `globalConstant`',
				'`PHP_VERSION_ID`',
				'`use const PHP_VERSION_ID;`',
				'`\PHP_VERSION_ID`',
				'computes with it',
				'A constant merely passed to a call is not one the compiler computes with.',
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->policy = new QualificationPolicy($values);
	}


	public function getVisitedNodes(): array
	{
		return [NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NamespaceNode && $node->name !== null) {
			$this->process($node, $context);
		}
	}


	private function process(NamespaceNode $scope, RuleContext $context): void
	{
		$uses = NameReferences::collectGlobalUses($scope, $context->getAnalysis(NameResolver::class));
		$imports = NameReferences::collectGlobalImports($scope);
		foreach ([SymbolKind::ClassLike, SymbolKind::Function, SymbolKind::Constant] as $kind) {
			foreach ($uses[$kind->name] ?? [] as $key => $occurrences) {
				$global = $occurrences[0][2];
				$compiled = $this->policy->isCompilerDecisive($kind) && self::isCompiled($kind, $global, $occurrences, $context);
				$decided = $this->policy->findGlobal($kind, $compiled);
				if ($decided !== null) {
					$this->writeSymbol($scope, $kind, $global, $occurrences, $imports[$kind->name][$key] ?? [], $decided[0], $decided[1], $context);
				}
			}
		}
	}


	/**
	 * @param  list<array{NameNode, string, string}>  $occurrences
	 * @param  list<array{UseNode, UseItemNode}>  $imports
	 * @param  list<string>  $forms
	 */
	private function writeSymbol(
		NamespaceNode $scope,
		SymbolKind $kind,
		string $global,
		array $occurrences,
		array $imports,
		array $forms,
		string $decision,
		RuleContext $context,
	): void
	{
		$wrong = array_values(array_filter($occurrences, fn(array $occurrence) => !in_array($occurrence[1], $forms, true)));
		$importGoes = $kind !== SymbolKind::ClassLike && $imports !== [] && !in_array(QualificationPolicy::Imported, $forms, true);
		if ($wrong === [] && !$importGoes) {
			return;
		}

		$item = $imports[0][1] ?? null;
		$alias = $item === null ? null : ($item->alias->text ?? $item->name->shortName);
		$subject = NameReferences::describe($kind, $global);
		$target = $this->findTarget($scope, $kind, $global, $occurrences, $forms, $alias, $decision, $context);
		if ($target === null) {
			// an import the markup leaves no line for or a silenced name refuses stays to be written by hand, one whose name
			// is taken is no import to write
			$first = $forms[0] === QualificationPolicy::Imported && self::isNameImportable($scope, $kind, $global, $context)
				? array_find($wrong, fn(array $occurrence) => !$context->isSilenced($occurrence[0], decision: $decision))
				: null;
			if ($first !== null) {
				$context->report($first[0], "$subject must be imported.", decision: $decision, fixable: false);
			}

			return;
		}

		$risk = $context->getAnalysis(NamespacedSymbols::class)->complete ? null : Risk::NameUncertain;
		$bare = fn(array $occurrence) => $occurrence[1] === QualificationPolicy::Bare;
		if ($target === QualificationPolicy::Imported && $alias === null) {
			// the import takes over every bare name of the symbol, so each of them is reported and has to agree, and
			// a qualified one shares its risk, the import it is written through being the same
			$groupRisk = array_any($wrong, $bare) ? $risk : null;
			$refused = false;
			$admitted = [];
			foreach ($wrong as $occurrence) {
				if ($context->report($occurrence[0], "$subject must be imported.", decision: $decision, risk: $groupRisk)) {
					$admitted[] = $occurrence;
				} else {
					$refused = $refused || $bare($occurrence);
				}
			}

			if (!$refused && $admitted !== []) {
				CodeWriter::addImport($scope, $kind, $global, $context);
				foreach ($admitted as [$name, $form, $spelled]) {
					if ($form !== QualificationPolicy::Bare) {
						$name->text = $spelled;
					}
				}
			}

			return;
		}

		$importStays = false;
		$aliasSpelled = false;
		foreach ($wrong as $occurrence) {
			[$name, $form, $spelled] = $occurrence;
			$named = NameReferences::describe($kind, $spelled);
			[$message, $written] = match ($target) {
				QualificationPolicy::Imported => ["$named must be imported.", $alias],
				QualificationPolicy::Backslashed => ["$named must be written with the leading backslash.", '\\' . $spelled],
				default => [
					$form === QualificationPolicy::Imported ? "$named must be written without the import." : "$named must be written without the leading backslash.",
					$spelled,
				],
			};
			if ($target === QualificationPolicy::Bare && $form === QualificationPolicy::Imported && strcasecmp($name->text, $global) === 0) {
				// the alias spelling the global name becomes bare by the removal of the import alone
				$aliasSpelled = true;
			} elseif ($context->report($name, $message, decision: $decision, risk: $bare($occurrence) || $target === QualificationPolicy::Bare ? $risk : null)) {
				$name->text = (string) $written;
			} else {
				$importStays = $importStays || $form === QualificationPolicy::Imported;
			}
		}

		foreach ($importGoes && !$importStays ? $imports : [] as [, $useItem]) {
			if ($context->report($useItem, "$subject must not be imported.", decision: $decision, risk: $aliasSpelled ? $risk : null)) {
				$useItem->remove();
			}
		}
	}


	/**
	 * The first of the forms the symbol can be written in: bare where the bare name reaches it, imported where the
	 * namespace imports it or an import of it can be added, fully qualified always; null where none is open.
	 * @param  list<array{NameNode, string, string}>  $occurrences
	 * @param  list<string>  $forms
	 */
	private function findTarget(
		NamespaceNode $scope,
		SymbolKind $kind,
		string $global,
		array $occurrences,
		array $forms,
		?string $alias,
		string $decision,
		RuleContext $context,
	): ?string
	{
		$bare = array_filter($occurrences, fn(array $occurrence) => $occurrence[1] === QualificationPolicy::Bare);
		foreach ($forms as $form) {
			$open = match ($form) {
				QualificationPolicy::Bare => $kind !== SymbolKind::ClassLike && self::canFallBack($scope, $kind, $global, $context),
				QualificationPolicy::Imported => $alias !== null || (
					// an added import would take over the bare names, which a tolerated bare form keeps and a silenced one refuses
					!($bare !== [] && in_array(QualificationPolicy::Bare, $forms, true))
					&& CodeWriter::canAddImport($scope)
					&& self::isNameImportable($scope, $kind, $global, $context)
					&& !array_any($bare, fn(array $occurrence) => $context->isSilenced($occurrence[0], decision: $decision))
				),
				default => true,
			};
			if ($open) {
				return $form;
			}
		}

		return null;
	}


	/**
	 * Whether the name of the global symbol is free for an import of it: no keyword, no import or declaration takes it,
	 * and for a class no name of the namespace the import would redirect.
	 */
	private static function isNameImportable(NamespaceNode $scope, SymbolKind $kind, string $global, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return !NameNode::fromText($global)->isKeyword()
			&& $resolver->isAliasFree($global, $kind, $scope)
			&& ($kind !== SymbolKind::ClassLike || array_all(
				array_keys(NameReferences::collectClassTargets($scope, $resolver, $context->getAnalysis(PhpDoc::class))[strtolower($global)] ?? []),
				fn(string $target) => strcasecmp($target, $global) === 0,
			));
	}


	/**
	 * Whether the bare name reaches the global function or constant once no import stands in the way: the namespace
	 * neither declares it in the file nor lists it, and an import of that name, if any, imports the global one.
	 */
	private static function canFallBack(NamespaceNode $scope, SymbolKind $kind, string $global, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$import = $resolver->getImports($kind, $scope)[NameReferences::toKey($kind, $global)] ?? null;
		if ($import === null) {
			return $resolver->getUnqualifiedResolution($global, $kind, $scope) !== UnqualifiedResolution::Namespaced;
		}

		// with the import of the name in place the name reaches the global one anyway, so what the bare name would reach without it is asked directly
		$namespaced = $context->getAnalysis(NamespacedSymbols::class);
		$namespace = $resolver->getNamespace($scope);
		return strcasecmp($import, $global) === 0
			&& $resolver->findDeclaration("$namespace\\$global", $kind) === null
			&& !($kind === SymbolKind::Function ? $namespaced->hasFunction("$namespace\\$global") : $namespaced->hasConstant("$namespace\\$global"));
	}


	/**
	 * Whether the compiler works with the global function or constant: a call PHP optimizes, an argument named or
	 * unpacked aside, or a constant PHP declares where it computes with it.
	 * @param  list<array{NameNode, string, string}>  $occurrences
	 */
	private static function isCompiled(SymbolKind $kind, string $name, array $occurrences, RuleContext $context): bool
	{
		return match ($kind) {
			SymbolKind::Function => array_any($occurrences, fn(array $occurrence) => ($call = $occurrence[0]->parent) instanceof Expression\FunctionCallNode
				&& $call->name === $occurrence[0]
				&& Compiler::isOptimizedCall($call, $name, $context, unpacked: true, named: true)),
			SymbolKind::Constant => $context->getAnalysis(PhpSymbols::class)->isBuiltinConstant($name)
				&& array_any($occurrences, fn(array $occurrence) => self::isComputed($occurrence[0], $context)),
			default => false,
		};
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
				&& Compiler::isKnownAtCompileTime($parent, $context, unqualified: true)
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
			|| (
				($parent instanceof IfNode || $parent instanceof ElseifNode || $parent instanceof WhileNode || $parent instanceof DoWhileNode || $parent instanceof Expression\TernaryNode)
				&& $parent->condition === $node
			);
	}


	/** Whether the node is a logical operator, the negation among them. */
	private static function isLogical(Node $node): bool
	{
		return ($node instanceof Expression\BinaryOpNode && $node->isLogical())
			|| ($node instanceof Expression\UnaryOpNode && $node->operator->is('!'));
	}
}
