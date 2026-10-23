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

	/** PER Coding Style is taken over PSR-12, which it succeeds, unless its residue is more than this many times larger */
	private const SuccessorResidue = 1.5;

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
		/** @var list<string>  the standards as near to the code, which nothing of the project decided between */
		public array $alternatives = [],
		/** the files of the sample the chosen standard alone would rewrite */
		public int $changedFiles = 0,
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
		[$chosen, $reason, $alternatives] = match (true) {
			$presets !== null => [(string) array_key_first($checks), StandardReason::Given, []],
			count($candidates) === 1 => [(string) array_key_first($checks), StandardReason::Formatter, []],
			default => self::choose($residues, [$project->framework, $project->formatterConfig?->standard]),
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
			$alternatives,
			$checks[$chosen]->changedFiles,
		);
	}


	/**
	 * The standard the code departs from in the fewest decisions; where the next one is within a tenth of it, the first of
	 * the hints among them (the standard of the framework of the project, the one its formatter names), else `perCs`
	 * among them, else the fewest. PSR-12 says nothing of what PHP brought since and so departs from less, which is why
	 * it yields to PER Coding Style within `SuccessorResidue`, unless a hint names it. A close call no hint decides
	 * leaves the other close standards as the alternatives.
	 * @param  array<string, int>  $residues  standard => its residue, the fewest first
	 * @param  list<?string>  $hints
	 * @return array{string, StandardReason, list<string>}
	 */
	private static function choose(array $residues, array $hints): array
	{
		$candidates = $residues;
		if (isset($candidates['psr12'], $candidates['perCs'])
			&& $candidates['perCs'] <= self::SuccessorResidue * $candidates['psr12']
			&& !in_array('psr12', $hints, true)
		) {
			unset($candidates['psr12']);
		}

		$best = (string) array_key_first($candidates);
		$close = array_keys(array_filter($candidates, fn(int $count) => $count <= self::CloseResidue * $candidates[$best]));
		$hinted = count($close) === 1 ? $best : array_find($hints, fn(?string $standard) => in_array($standard, $close, true));
		$chosen = $hinted ?? (in_array(CorePlugin::Standards[0], $close, true) ? CorePlugin::Standards[0] : $best);
		return [
			$chosen,
			$chosen === 'perCs' && ($residues['psr12'] ?? PHP_INT_MAX) < $residues['perCs']
				? StandardReason::Successor
				: StandardReason::Fewest,
			$hinted === null ? array_values(array_diff($close, [$chosen])) : [],
		];
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
			StandardReason::Successor => $this->describe() . ', the successor of PSR-12, which your code departs from in fewer decisions',
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
