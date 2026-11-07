<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{PhpSymbols, Types};
use DressCode\Config\Layer;
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Upgrading\MemberMapGrammar;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\Expression\{EmptyNode, EvalNode, ExitNode, FunctionCallNode, IssetNode, PrintNode};
use PhpSyntax\Nodes\{NameNode, UseItemNode};
use PhpSyntax\Nodes\Statement\UnsetNode;


/**
 * References of the classes, interfaces and enums, calls of the functions, the constructs written as one among them
 * (`eval()`, `exit()`, `print`, `empty()`, `isset()`, `unset()`), and uses of the constants a project, or
 * a library it stands on, says its code must not have, each reported with what the map says to do instead where it
 * says it; nothing is rewritten, which is what the maps are for where nothing else could simply stand for the symbol.
 * A class is reported wherever its name stands, a type, an instantiation, a static access, an attribute, but not in an
 * import, which names nothing once the references are gone and goes with them, and so is a constant. The name of a
 * function or a constant is resolved the way PHP does, so `var_dump()` inside a namespace is the global function
 * unless the file declares or imports another one; a pattern with `*` matches any part of the name and one with
 * a backslash the fully qualified name, a constant in its letter case. What the data of a package forbid is deprecated
 * where the code still has the symbol and removed where it has not, as the types tell, or for a function or a constant
 * of PHP the catalog of PHP at the lowest version of the target where the run has no types; forbidden otherwise.
 */
#[RuleInfo(
	Stage::Structure,
	decisions: ['upgrading.packages', 'upgrading.php.deprecatedApi', self::Classes, self::Functions, self::Constants],
	analyses: [NameResolver::class, Types::class, PhpSymbols::class],
)]
final class ForbiddenSymbolsRule extends NodeRule
{
	public const Classes = MemberMapGrammar::ForbiddenClasses;
	public const Functions = MemberMapGrammar::ForbiddenFunctions;
	public const Constants = MemberMapGrammar::ForbiddenConstants;

	/** the constructs written as a call, which the map of functions names by their keyword */
	private const Constructs = [EvalNode::class, ExitNode::class, PrintNode::class, EmptyNode::class, IssetNode::class, UnsetNode::class];

	/**
	 * @var array<string, array{string, string, ?Layer, string}>  lowercased class => the class as the map spells it, the
	 * end of the message, the section of the upgrading data of a package that laid it and the decision it is reported under
	 */
	private array $classes = [];

	/**
	 * @var list<array{string, ?string, ?Layer, string}>  regular expression, what to do instead, the section of the
	 * upgrading data of a package that laid it, the decision it is reported under
	 */
	private array $functions = [];

	/** @var array<string, true>  lowercased functions withdrawn from the map, which no pattern reaches */
	private array $withdrawnFunctions = [];

	/**
	 * @var list<array{string, ?string, ?Layer, string}>  regular expression, what to do instead, the section of the
	 * upgrading data of a package that laid it, the decision it is reported under
	 */
	private array $constants = [];

	/** @var array<string, true>  constants withdrawn from the map, which no pattern reaches */
	private array $withdrawnConstants = [];


	public function configure(Values $values): void
	{
		$this->classes = [];
		foreach ($values->readMap(self::Classes) as $class => $message) {
			$this->classes[strtolower(ltrim((string) $class, '\\'))] = [
				ltrim((string) $class, '\\'),
				$message === null ? '' : ": $message",
				$values->getEntryOrigin(self::Classes, (string) $class),
				$values->getEntryDecision(self::Classes, (string) $class),
			];
		}

		[$this->functions, $this->withdrawnFunctions] = self::readPatterns($values, self::Functions, caseInsensitive: true);
		[$this->constants, $this->withdrawnConstants] = self::readPatterns($values, self::Constants, caseInsensitive: false);
	}


	/**
	 * The patterns of a map of names with what to do instead, and the names withdrawn from it.
	 * @return array{list<array{string, ?string, ?Layer, string}>, array<string, true>}
	 */
	private static function readPatterns(Values $values, string $path, bool $caseInsensitive): array
	{
		$patterns = [];
		foreach ($values->readMap($path) as $pattern => $sentence) {
			$patterns[] = [
				'~^' . str_replace('\*', '.*', preg_quote(ltrim((string) $pattern, '\\'), '~')) . '$~' . ($caseInsensitive ? 'i' : ''),
				$sentence,
				$values->getEntryOrigin($path, (string) $pattern),
				$values->getEntryDecision($path, (string) $pattern),
			];
		}

		$withdrawn = array_map(fn($name) => ltrim((string) $name, '\\'), $values->findWithdrawnNames($path));
		return [$patterns, array_fill_keys($caseInsensitive ? array_map(strtolower(...), $withdrawn) : $withdrawn, true)];
	}


	public function getVisitedNodes(): array
	{
		return [
			...($this->classes !== [] || $this->constants !== [] ? [NameNode::class] : []),
			...($this->functions !== [] ? [FunctionCallNode::class, ...self::Constructs] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NameNode && $node->symbolKind === SymbolKind::ClassLike) {
			$this->checkClass($node, $context);
		} elseif ($node instanceof NameNode && $node->symbolKind === SymbolKind::Constant) {
			$this->checkConstant($node, $context);
		} elseif ($node instanceof FunctionCallNode) {
			$this->checkFunction($node, $context);
		} elseif ($node instanceof Node && in_array($node::class, self::Constructs, true)) {
			$this->checkConstruct($node, $context);
		}
	}


	private function checkClass(NameNode $node, RuleContext $context): void
	{
		if ($this->classes === [] || $node->parent instanceof UseItemNode || !$node->isReference()) {
			return;
		}

		$entry = $this->classes[strtolower($context->getAnalysis(NameResolver::class)->resolveClass($node))] ?? null;
		if ($entry !== null) {
			[$class, $sentence, $source, $decision] = $entry;
			$types = $context->findAnalysis(Types::class);
			$verdict = Retirement::of($source !== null, $types === null ? null : $types->findClassName($class) !== null);
			$context->report($node, "Class `$class` is $verdict->value$sentence.", decision: $decision, fixable: false, source: $source);
		}
	}


	private function checkFunction(FunctionCallNode $node, RuleContext $context): void
	{
		if (!$node->name instanceof NameNode || $node->name->isKeyword()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveFunction($node->name);
		$entry = isset($this->withdrawnFunctions[strtolower($resolved)]) ? null : self::findEntry($this->functions, $resolved);
		if ($entry !== null) {
			[$sentence, $source, $decision] = $entry;
			$types = $context->findAnalysis(Types::class);
			$php = $context->getAnalysis(PhpSymbols::class);
			$verdict = Retirement::of($source !== null, match (true) {
				$types !== null => $types->findFunctionName($resolved) !== null,
				$php->isBuiltinFunction($resolved) => $php->isBuiltinFunction($resolved, $context->phpVersion),
				default => null,
			});
			$context->report(
				$node->name,
				"Function `$resolved()` is $verdict->value$sentence.",
				decision: $decision,
				fixable: false,
				source: $source,
			);
		}
	}


	private function checkConstruct(Node $node, RuleContext $context): void
	{
		$keyword = $node->getFirstToken();
		if ($keyword === null) {
			return;
		}

		$name = strtolower($keyword->text);
		$entry = isset($this->withdrawnFunctions[$name]) ? null : self::findEntry($this->functions, $name);
		if ($entry !== null) {
			[$sentence, $source, $decision] = $entry;
			$verdict = Retirement::of($source !== null, exists: true);
			$context->report(
				$keyword,
				"Function `$name()` is $verdict->value$sentence.",
				decision: $decision,
				fixable: false,
				source: $source,
			);
		}
	}


	private function checkConstant(NameNode $node, RuleContext $context): void
	{
		if ($this->constants === [] || $node->parent instanceof UseItemNode || !$node->isReference() || $node->isKeyword()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveConstant($node);
		$entry = isset($this->withdrawnConstants[$resolved]) ? null : self::findEntry($this->constants, $resolved);
		if ($entry !== null) {
			[$sentence, $source, $decision] = $entry;
			$types = $context->findAnalysis(Types::class);
			$verdict = Retirement::of($source !== null, match (true) {
				$types !== null => $types->knowsConstant($resolved),
				$context->getAnalysis(PhpSymbols::class)->isBuiltinConstant($resolved) => true,
				default => null,
			});
			$context->report(
				$node,
				"Constant `$resolved` is $verdict->value$sentence.",
				decision: $decision,
				fixable: false,
				source: $source,
			);
		}
	}


	/**
	 * The first pattern the name matches, as the end of its message, empty where its entry says nothing to do instead,
	 * the section of the upgrading data of a package that laid it and the decision it is reported under; null where none
	 * matches.
	 * @param  list<array{string, ?string, ?Layer, string}>  $patterns
	 * @return ?array{string, ?Layer, string}
	 */
	private static function findEntry(array $patterns, string $name): ?array
	{
		foreach ($patterns as [$pattern, $sentence, $source, $decision]) {
			if (preg_match($pattern, $name)) {
				return [$sentence === null ? '' : ": $sentence", $source, $decision];
			}
		}

		return null;
	}
}
