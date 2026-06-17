<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Analyses, Rule, Style, Values};
use DressCode\Engine\Gate;
use PhpSyntax\Analyses\NamespacedSymbols;


/**
 * What a configuration comes to: every decision with its value and the layers that set it, the rules in the order
 * they run, the style, the target versions, the plugins, what the namespaces declare, and the rules that do not run
 * with the reason. One resolution serves the run and whoever prints the configuration, so that what the reader is
 * shown is what the rules were given.
 * @internal
 */
final readonly class ResolvedConfig
{
	public function __construct(
		/** @var list<ResolvedRule>  the active ones in the order they run, the rest behind them */
		public array $rules,
		/** the characters of one level of indentation */
		public string $indent,
		/** `"\n"`, `"\r\n"` or `'majority'` */
		public string $lineEnding,
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
		/** @var list<class-string>  the plugins the configuration and the command line use */
		public array $plugins = [],
		/** @var array<string, ResolvedDecision>  every decision of the catalogue by its path */
		public array $decisions = [],
		/** @var array<string, true>  the decisions whose fixes that may change what the code does the project accepts */
		public array $fixRisky = [],
		/** @var array<string, true>  the decisions whose violations only warn */
		public array $warnOnly = [],
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
			$this->lineEnding === 'majority' ? "\n" : $this->lineEnding,
		);
	}


	/** The analyses of the run. */
	public function createAnalyses(?NamespacedSymbols $symbols = null): Analyses\Registry
	{
		return new Analyses\Registry($symbols ?? $this->toNamespacedSymbols());
	}
}
