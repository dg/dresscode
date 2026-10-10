<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Analyses, DecisionKind, ImportStyle, Rule, Style, Values};
use DressCode\Engine\Gate;
use PhpSyntax\Analyses\NamespacedSymbols;


/**
 * What a configuration comes to: every decision with its value and the layers that set it, the rules in the order
 * they run, the style, the target versions, the plugins, what the namespaces declare, and the rules that do not run
 * with the reason. One resolution serves the run, the result cache and whoever prints the
 * configuration, so that what the reader is shown is what the rules were given.
 * @internal
 */
final readonly class ResolvedConfig
{
	public function __construct(
		/** @var list<ResolvedRule>  the active ones in the order they run, the rest behind them */
		public array $rules,
		/** the characters of one level of indentation */
		public string $indent,
		/** `"\n"` or `"\r\n"`; null for the one each file uses most */
		public ?string $lineEnding,
		public string $phpVersion,
		/** @var list<string>  names of the presets in the order they are laid, what a preset uses before it */
		public array $use,
		/** the values the rules read, with the mask of the run */
		public Values $values,
		/** @var array<string, string>  fully qualified name of a function the namespaces declare => the layer that named it first */
		public array $namespacedFunctions = [],
		/** @var array<string, string>  fully qualified name of a constant the namespaces declare => the layer that named it first */
		public array $namespacedConstants = [],
		/** @var 'certain'|'uncertain'  certain when the namespaces declare no function and no constant beyond those */
		public string $nameResolution = 'uncertain',
		/** the widest line the rules keep to; null for none */
		public ?int $lineLength = null,
		/** how many columns a tab counts for in the width of a line */
		public int $tabWidth = 4,
		/** `'phpstan'` when the types of the code come from the PHPStan of the project; null when the rules have none */
		public ?string $typeAnalysis = null,
		/** @var array<string, string>  package => the version the configuration says its code is written for */
		public array $packageTargets = [],
		/** @var list<class-string>  the plugins the configuration and the command line use */
		public array $plugins = [],
		/** @var array<string, list<string>>  pattern of a comment => the decisions it silences where it stands */
		public array $suppressionComments = [],
		/** @var array<string, ResolvedDecision>  every decision of the catalogue by its path */
		public array $decisions = [],
		/** @var array<string, true>  the decisions whose fixes that may change what the code does the project accepts */
		public array $fixRisky = [],
		/** @var array<string, true>  the decisions whose violations only warn */
		public array $warnOnly = [],
		/** @var list<ResolvedConfig>  what each override of the configuration comes to for a file it alone matches */
		public array $overrides = [],
	) {
	}


	/**
	 * The gate of the reports of every rule, by the class of the rule.
	 * @return array<class-string, Gate>
	 */
	public function getGates(): array
	{
		$byRule = [];
		foreach ($this->decisions as $resolved) {
			foreach ($resolved->rules as $class) {
				$byRule[$class][] = $resolved->decision;
			}
		}

		$gates = [];
		foreach ($byRule as $class => $decisions) {
			$gates[$class] = Gate::fromValues($decisions, $this->values);
		}

		return $gates;
	}


	/** @return list<ResolvedRule> */
	public function getActiveRules(): array
	{
		return array_values(array_filter($this->rules, fn(ResolvedRule $rule) => $rule->isActive()));
	}


	/** @param  class-string<Rule>  $class */
	public function findRule(string $class): ?ResolvedRule
	{
		return array_find($this->rules, fn(ResolvedRule $rule) => $rule->class === $class);
	}


	/** What the namespaces declare outside the files, as the resolver of names takes it. */
	public function toNamespacedSymbols(): NamespacedSymbols
	{
		return new NamespacedSymbols(array_keys($this->namespacedFunctions), array_keys($this->namespacedConstants), $this->nameResolution === 'certain');
	}


	/** The style the rules write the code in, the line ending of `majority` being LF until a file says otherwise. */
	public function createStyle(): Style
	{
		return new Style(
			$this->indent,
			$this->lineEnding ?? "\n",
			$this->tabWidth,
			$this->lineLength,
			ImportStyle::fromValues($this->values),
		);
	}


	/** The analyses of the run, with the plan of the indentation the decisions give in the style. */
	public function createAnalyses(Style $style, ?NamespacedSymbols $symbols = null): Analyses\Registry
	{
		$registry = new Analyses\Registry($symbols ?? $this->toNamespacedSymbols());
		$registry->register(Analyses\IndentationPlan::class, Analyses\IndentationPlan::createFactory($this->values, $style));
		return $registry;
	}


	/**
	 * Everything a result depends on besides the file and the versions of the packages: the active rules, whether a
	 * closure builds them and whether their risky fixes are made, the values of the decisions, the style, the target
	 * version, what the namespaces declare, the types and the same of every override. A changed default is a changed
	 * value here, which a description of what the configuration said would miss.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$rules = [];
		foreach ($this->getActiveRules() as $rule) {
			$rules[$rule->class] = ['factory' => $rule->factory !== null, 'fixRisky' => $rule->fixRisky];
		}

		return [
			'rules' => $rules,
			'indent' => $this->indent,
			'lineEnding' => $this->lineEnding,
			'lineLength' => $this->lineLength,
			'tabWidth' => $this->tabWidth,
			'php' => $this->phpVersion,
			'namespaces' => [array_keys($this->namespacedFunctions), array_keys($this->namespacedConstants), $this->nameResolution],
			'typeAnalysis' => $this->typeAnalysis,
			'suppressionComments' => $this->suppressionComments,
			'decisions' => array_map(fn(ResolvedDecision $decision) => $decision->value->toData(), $this->decisions),
			'fixRisky' => array_keys($this->fixRisky),
			'selected' => array_keys(array_filter($this->decisions, fn(ResolvedDecision $decision) => $decision->decision->kind !== DecisionKind::Parameter && $this->values->isSelected($decision->decision->path))),
			'overrides' => array_map(fn(self $override) => $override->toArray(), $this->overrides),
		];
	}
}
