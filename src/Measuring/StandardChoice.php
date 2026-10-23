<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{CorePlugin, ResolvedDecision, RunnerFactory};
use DressCode\Interop\Formatter;
use function count;


/**
 * The standard `dresscode init` writes, and why: the one given, the one Laravel Pint formats the project in, else the
 * one the code departs from in the fewest decisions over the sample, its residue.
 * @internal
 */
final readonly class StandardChoice
{
	/** a standard whose residue is within this many times the smallest one fits the code as well */
	private const CloseResidue = 1.1;

	/** the full names of the standards */
	private const Names = [
		'perCs' => 'PER Coding Style',
		'psr12' => 'PSR-12',
		'nette' => 'the Nette Coding Standard',
		'symfony' => 'the Symfony Coding Standards',
		'laravel' => 'the style of Laravel Pint',
	];


	private function __construct(
		/** @var list<string>  as the configuration names them */
		public array $presets,
		public StandardReason $reason,
		/** @var array<string, int>  standard => the decisions the code departs from it in over the sample, the fewest first */
		public array $residues,
		/** @var list<string>  the decisions the code departs from the chosen standard in */
		public array $residue,
		/** @var array<string, ResolvedDecision>  what the chosen standard decides */
		public array $decisions,
		/** @var list<string>  the files the checks found unparsable */
		public array $unparsed,
	) {
	}


	/**
	 * The standards to check: the presets given, the one of Laravel Pint, else every standard, `laravel` only for a
	 * project of Laravel, its preset saying nothing of code without it.
	 * @param  ?list<string>  $presets
	 * @return list<list<string>>
	 */
	public static function listCandidates(Project $project, ?array $presets): array
	{
		$pint = $presets === null ? $project->findPintStandard() : null;
		return match (true) {
			$presets !== null => [$presets],
			$pint !== null => [[$pint]],
			default => array_map(
				fn(string $standard) => [$standard],
				array_filter(CorePlugin::Standards, fn(string $standard) => $standard !== 'laravel' || $project->framework === 'laravel'),
			),
		};
	}


	/**
	 * Checks the candidates over the base, the configuration of the sample, and chooses.
	 * @param  ?list<string>  $presets  as the configuration names them; null to choose
	 * @param  array<string, mixed>  $base
	 * @throws ConfigurationException
	 */
	public static function make(Project $project, ?array $presets, ProbeRunner $runner, array $base): self
	{
		$candidates = self::listCandidates($project, $presets);
		$probes = [];
		foreach ($candidates as $candidate) {
			$probes[implode(', ', $candidate)] = [...$base, 'use' => $candidate];
		}

		$checks = $runner->run($probes);
		$residues = array_map(fn(ProbeResult $check) => count($check->findResidue()), $checks);
		asort($residues);
		[$chosen, $reason] = match (true) {
			$presets !== null => [(string) array_key_first($checks), StandardReason::Given],
			count($candidates) === 1 => [(string) array_key_first($checks), StandardReason::Formatter],
			default => [self::choose($residues, [$project->framework, $project->formatterConfig?->standard]), StandardReason::Fewest],
		};
		$presets = array_combine(array_keys($probes), $candidates)[$chosen];
		$decisions = (new RunnerFactory)->resolve(new Config(use: $presets), $project->root)->resolvedConfig->decisions;
		return new self(
			$presets,
			$reason,
			$residues,
			array_values(array_filter($checks[$chosen]->findResidue(), fn(string $path) => isset($decisions[$path]))),
			$decisions,
			array_values(array_unique(array_merge(...array_map(fn(ProbeResult $check) => $check->syntaxErrors, array_values($checks))))),
		);
	}


	/**
	 * The standard the code departs from in the fewest decisions; where the next one is within a tenth of it, the first of
	 * the hints among them (the standard of the framework of the project, the one its formatter names), else `perCs`
	 * among them, else the fewest.
	 * @param  array<string, int>  $residues  standard => its residue, the fewest first
	 * @param  list<?string>  $hints
	 */
	private static function choose(array $residues, array $hints): string
	{
		$best = (string) array_key_first($residues);
		$close = array_keys(array_filter($residues, fn(int $count) => $count <= self::CloseResidue * $residues[$best]));
		return count($close) === 1
			? $best
			: array_find([...$hints, CorePlugin::Standards[0]], fn(?string $standard) => in_array($standard, $close, true)) ?? $best;
	}


	/** The name of the standard a reader knows it by: "PER Coding Style". */
	public function describe(): string
	{
		return implode(', ', array_map(fn(string $preset) => self::Names[$preset] ?? $preset, $this->presets));
	}


	/** Why the standard was chosen, as the comment beside `use` says it; nothing for one given. */
	public function describeReason(): string
	{
		return match ($this->reason) {
			StandardReason::Given => '',
			StandardReason::Formatter => Formatter::Pint->value . ' formats the project in this style',
			StandardReason::Fewest => $this->describe() . ', of the standards the one your code departs from in the fewest decisions',
		};
	}


	/** The value of the decision as the configuration writes it: `1`, `'$a + $b'`, `[apart, closingApart]`. */
	public function formatValue(string $path, mixed $value): string
	{
		return $this->decisions[$path]->decision->accept($value)->toWrittenText();
	}


	/** Whether the value is the one the standard gives the decision. */
	public function isStandard(string $path, mixed $value): bool
	{
		$standard = $this->decisions[$path] ?? null;
		return $standard !== null && $standard->decision->accept($value)->toData() === $standard->value->toData();
	}


	/**
	 * What the standards of the core give the decision, the values a count is measured under besides its own.
	 * @return list<mixed>
	 */
	public static function listStandardValues(string $path): array
	{
		static $standards;
		$standards ??= array_map(
			fn(string $name) => (new RunnerFactory)->resolve(new Config(use: [$name]), sys_get_temp_dir())->resolvedConfig->decisions,
			CorePlugin::Standards,
		);
		return array_values(array_map(fn(array $decisions) => ($decisions[$path] ?? null)?->value->toData(), $standards));
	}
}
