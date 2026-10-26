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
 * one a fix under it alone changes the fewest files of the sample under, and the decisions the code departs from it in,
 * its residue.
 * @internal
 */
final readonly class StandardChoice
{
	/** a standard changing at most this many times the fewest files fits the code as well */
	private const CloseShare = 1.1;

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
		/** @var array<string, int>  standard => the files of the sample a fix under it alone rewrites, the fewest first */
		public array $changedFiles,
		/** @var list<string>  the decisions the code departs from the chosen standard in */
		public array $residue,
		/** @var array<string, ResolvedDecision>  what the chosen standard decides */
		public array $decisions,
		/** @var list<string>  the files the checks found unparsable */
		public array $unparsed,
		/** @var list<string>  the files a rule failed in under some standard, which none of them counted */
		public array $failed = [],
		/** a rule failed under every standard there was to choose from, so the one chosen may only seem the nearest */
		public bool $unsure = false,
	) {
	}


	/**
	 * The standards to check: the presets given, the one of Laravel Pint, else every standard, `laravel` only for a
	 * project of Laravel, its preset saying nothing of code without it.
	 * @param  ?list<string>  $presets
	 * @return list<list<string>>
	 */
	public static function listCandidates(ProjectFacts $project, ?array $presets): array
	{
		$pint = $presets === null ? $project->findPintStandard() : null;
		return match (true) {
			$presets !== null => [$presets],
			$pint !== null => [[$pint]],
			default => array_map(
				fn(string $standard) => [$standard],
				array_filter(CorePlugin::Standards, fn(string $standard) => $standard !== 'laravel' || $project->usesLaravel()),
			),
		};
	}


	/**
	 * Checks the candidates over the base, the configuration of the sample, and chooses.
	 * @param  ?list<string>  $presets  as the configuration names them; null to choose
	 * @param  array<string, mixed>  $base
	 * @throws ConfigurationException
	 */
	public static function measure(ProjectFacts $project, ?array $presets, ProbeRunner $runner, array $base): self
	{
		$candidates = self::listCandidates($project, $presets);
		$probes = [];
		foreach ($candidates as $candidate) {
			$probes[implode(', ', $candidate)] = [...$base, 'use' => $candidate];
		}

		$checks = $runner->run($probes);
		// in the order of the candidates, whichever probe ended first, so that a tie always falls the same way
		$changed = array_map(fn(string $name) => $checks[$name]->changedFiles, array_combine(array_keys($probes), array_keys($probes)));
		asort($changed);
		// a standard a rule failed under is not measured whole, so its count may be short; it is chosen only where all are
		$whole = array_filter($changed, fn(string $standard) => $checks[$standard]->failed === [], ARRAY_FILTER_USE_KEY);
		$unsure = $presets === null && count($candidates) > 1 && $whole === [];
		[$chosen, $reason] = match (true) {
			$presets !== null => [(string) array_key_first($checks), StandardReason::Given],
			count($candidates) === 1 => [(string) array_key_first($checks), StandardReason::Formatter],
			default => [self::choose($whole ?: $changed, $project->formatterConfig?->standard), StandardReason::Fewest],
		};
		$presets = array_combine(array_keys($probes), $candidates)[$chosen];
		$decisions = (new RunnerFactory)->resolve(new Config(use: $presets), $project->root)->resolvedConfig->decisions;
		return new self(
			$presets,
			$reason,
			$changed,
			array_values(array_filter($checks[$chosen]->findResidue(), fn(string $path) => isset($decisions[$path]))),
			$decisions,
			array_values(array_unique(array_merge(...array_map(fn(ProbeResult $check) => $check->syntaxErrors, array_values($checks))))),
			array_values(array_unique(array_merge(...array_map(fn(ProbeResult $check) => $check->failed, array_values($checks))))),
			$unsure,
		);
	}


	/**
	 * The standard a fix changes the fewest files under; where the next one is within a tenth of it, the one the formatter
	 * of the project names if it is among them, else `perCs` among them, else the fewest.
	 * @param  array<string, int>  $changed  standard => the files a fix under it changes, the fewest first
	 * @param  ?string  $hint  the standard the formatter of the project names
	 */
	private static function choose(array $changed, ?string $hint): string
	{
		$best = (string) array_key_first($changed);
		$close = array_keys(array_filter($changed, fn(int $count) => $count <= self::CloseShare * $changed[$best]));
		$hinted = count($close) === 1 ? $best : (in_array($hint, $close, true) ? $hint : null);
		return $hinted ?? (in_array(CorePlugin::Standards[0], $close, true) ? CorePlugin::Standards[0] : $best);
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
			StandardReason::Fewest => $this->describe() . ', of the standards the one a fix changes the fewest files of your code under',
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
