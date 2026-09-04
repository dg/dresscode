<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use PhpSyntax\Analyses\NamespacedSymbols;
use function count;


/**
 * What a configuration comes to: the rules in the order they run, each with the options it ends up with and
 * with the layers that set them, the style, the target version of PHP, what the namespaces declare, and the
 * rules that do not run with the reason. One resolution serves the run, the result cache and whoever prints the
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
		/** `"\n"`, `"\r\n"` or `'majority'` */
		public string $eol,
		public string $phpVersion,
		/** @var list<string>  names of the presets, parents first */
		public array $presets,
		/** @var list<string>  names of the groups any layer asked for, in the order of their first mention */
		public array $groups = [],
		/** @var array<string, string>  fully qualified name of a function the namespaces declare => the layer that named it first */
		public array $namespacedFunctions = [],
		/** @var array<string, string>  fully qualified name of a constant the namespaces declare => the layer that named it first */
		public array $namespacedConstants = [],
		/** @var 'certain'|'uncertain'  certain when the namespaces declare no function and no constant beyond those */
		public string $nameResolution = 'uncertain',
		/** the widest line the rules keep to; null for none */
		public ?int $lineLength = null,
		/** `'phpstan'` when the types of the code come from the PHPStan of the project; null when the rules have none */
		public ?string $types = null,
	) {
	}


	/** @return list<ResolvedRule> */
	public function getActiveRules(): array
	{
		return array_values(array_filter($this->rules, fn(ResolvedRule $rule) => $rule->isActive()));
	}


	public function getRule(string $name): ?ResolvedRule
	{
		return array_find($this->rules, fn(ResolvedRule $rule) => $rule->name === $name);
	}


	/** What the namespaces declare outside the files, as the resolver of names takes it. */
	public function toNamespacedSymbols(): NamespacedSymbols
	{
		return new NamespacedSymbols(array_keys($this->namespacedFunctions), array_keys($this->namespacedConstants), $this->nameResolution === 'certain');
	}


	/**
	 * Everything a result depends on besides the file and the versions of the packages: the active rules with the
	 * options they end up with, whether a closure builds them and whether their risky fixes are made, the style, the
	 * target version, what the namespaces declare and the types. A changed default is a changed value here, which
	 * a description of what the configuration said would miss.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$rules = [];
		foreach ($this->getActiveRules() as $rule) {
			$rules[$rule->name] = ['options' => $rule->options, 'factory' => $rule->factory !== null, 'fixRisky' => $rule->fixRisky];
		}

		return [
			'rules' => $rules,
			'indent' => $this->indent,
			'eol' => $this->eol,
			'lineLength' => $this->lineLength,
			'php' => $this->phpVersion,
			'namespaces' => [array_keys($this->namespacedFunctions), array_keys($this->namespacedConstants), $this->nameResolution],
			'types' => $this->types,
		];
	}


	public function countActive(): int
	{
		return count($this->getActiveRules());
	}
}
