<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\Versions;
use DressCode\ConfigurationException;
use Nette\IOException;
use Nette\Neon\{Exception as NeonException, Neon};
use Nette\Utils\FileSystem;
use function is_array, is_bool, is_string;


/**
 * The plan of an upgrade as the file `dresscode-upgrade.neon` writes it, which the team commits, reviews and edits: a
 * step left out with `skip: true`, the topics of a fix narrowed, the commands of the tests given. The one shape of the
 * namespace a user writes, so it is read strictly: a key it does not know or a value of another type is refused with
 * where it stands, rather than read as something else.
 * @internal
 */
final class PlanFile
{
	/** the name of the file in the root of the project */
	public const Name = 'dresscode-upgrade.neon';

	/** the version of the shape of the file */
	public const Version = 1;

	/** the keys of a plan */
	private const Keys = ['version', 'dresscode', 'data', 'targets', 'paths', 'tests', 'steps'];

	/** the keys of a step that its kind takes, beside `title`, `kind` and `skip` */
	private const StepKeys = ['fix' => ['only'], 'composer' => ['require', 'update'], 'manual' => ['suggest']];


	/** Whether the project in the root has a saved plan. */
	public static function exists(string $root): bool
	{
		return is_file("$root/" . self::Name);
	}


	/**
	 * The saved plan of the project in the root.
	 * @throws ConfigurationException  for a file that is no plan
	 */
	public static function read(string $root): Plan
	{
		$file = "$root/" . self::Name;
		try {
			$data = Neon::decode(FileSystem::read($file));
		} catch (NeonException|IOException $e) {
			throw new ConfigurationException("The plan `$file` cannot be read: {$e->getMessage()}", previous: $e);
		}

		$fail = fn(string $what) => throw new ConfigurationException("The plan `$file`: $what.");
		if (!is_array($data)) {
			$fail('a map of `version`, `targets` and `steps` is expected');
		} elseif (($data['version'] ?? null) !== self::Version) {
			$fail('`version: ' . self::Version . '` is expected, the plan of another version of DressCode is made anew');
		} elseif (($unknown = array_diff(array_keys($data), self::Keys)) !== []) {
			$fail('`' . reset($unknown) . '` is no key of a plan');
		}

		$targets = [];
		foreach (self::readMap($data['targets'] ?? [], 'targets', $fail) as $package => $version) {
			if (!Versions::isVersion($version)) {
				$fail("`targets.$package` must be a version, as `'8.4'`");
			}

			$targets[] = new Target($package, $version);
		}

		$tests = self::readMap($data['tests'] ?? [], 'tests', $fail);
		if (($unknown = array_diff(array_keys($tests), ['command', 'quick'])) !== []) {
			$fail('`tests.' . reset($unknown) . '` is no key of `tests`, which takes `command` and `quick`');
		}

		$steps = $data['steps'] ?? [];
		if (!is_array($steps) || !array_is_list($steps)) {
			$fail('`steps` must be a list');
		}

		foreach ($steps as $index => $step) {
			$steps[$index] = self::readStep($step, 'steps.' . ($index + 1), $fail);
		}

		if ($targets === [] || $steps === []) {
			$fail('`targets` and `steps` are expected');
		}

		return new Plan(
			$targets,
			$steps,
			self::readString($data['dresscode'] ?? '', 'dresscode', $fail),
			self::readMap($data['data'] ?? [], 'data', $fail),
			$tests['command'] ?? null,
			$tests['quick'] ?? null,
			self::readList($data['paths'] ?? [], 'paths', $fail),
		);
	}


	/** Saves the plan into the root of its project. */
	public static function write(Plan $plan, string $root): void
	{
		$steps = array_map(fn(Step $step) => array_filter([
			'title' => $step->title,
			'kind' => $step->kind->value,
			'only' => $step->only,
			'require' => $step->require,
			'update' => $step->update || $step->kind !== StepKind::Composer ? null : false,
			'suggest' => $step->suggest,
			'skip' => $step->skip ?: null,
		], fn($value) => $value !== null && $value !== []), $plan->steps);
		$data = array_filter([
			'version' => self::Version,
			'dresscode' => $plan->dresscode,
			'data' => $plan->data,
			'targets' => Target::collectVersions($plan->targets),
			'paths' => $plan->paths,
			'tests' => array_filter(['command' => $plan->testCommand, 'quick' => $plan->quickTestCommand]),
			'steps' => $steps,
		], fn($value) => $value !== []);
		$header = '# dresscode upgrade: the plan for ' . implode(', ', array_map(fn(Target $target) => $target->format(), $plan->targets))
			. "; set `skip: true` to leave a step out, and `dresscode upgrade --continue` runs what is left\n\n";
		FileSystem::write("$root/" . self::Name, $header . Neon::encode($data, blockMode: true));
	}


	/** @param  \Closure(string): never  $fail */
	private static function readStep(mixed $step, string $at, \Closure $fail): Step
	{
		$kind = is_array($step) && is_string($step['kind'] ?? null) ? StepKind::tryFrom($step['kind']) : null;
		if ($kind === null) {
			$fail("`$at` must give its `kind`, `fix`, `composer` or `manual`");
		} elseif (($unknown = array_diff(array_keys($step), ['title', 'kind', 'skip', ...self::StepKeys[$kind->value]])) !== []) {
			$fail("`$at." . reset($unknown) . "` is no key of a step of the kind `$kind->value`");
		}

		$step = new Step(
			$kind,
			self::readString($step['title'] ?? $kind->value, "$at.title", $fail),
			self::readList($step['only'] ?? [], "$at.only", $fail),
			self::readMap($step['require'] ?? [], "$at.require", $fail),
			self::readBool($step['update'] ?? true, "$at.update", $fail),
			isset($step['suggest']) ? self::readString($step['suggest'], "$at.suggest", $fail) : null,
			self::readBool($step['skip'] ?? false, "$at.skip", $fail),
		);
		return match (true) {
			$kind === StepKind::Fix && $step->only === [] => $fail("`$at.only` must name the topics the step fixes"),
			$kind === StepKind::Composer && $step->require === [] => $fail("`$at.require` must give the constraints the step writes"),
			default => $step,
		};
	}


	/**
	 * @param  \Closure(string): never  $fail
	 * @return list<string>
	 */
	private static function readList(mixed $value, string $at, \Closure $fail): array
	{
		return is_array($value) && array_is_list($value) && array_all($value, fn($item) => is_string($item))
			? $value
			: $fail("`$at` must be a list of strings");
	}


	/**
	 * @param  \Closure(string): never  $fail
	 * @return array<string, string>
	 */
	private static function readMap(mixed $value, string $at, \Closure $fail): array
	{
		return is_array($value) && array_all($value, fn($item, $key) => is_string($key) && is_string($item))
			? $value
			: $fail("`$at` must be a map of strings, a version quoted as `'8.4'`");
	}


	/** @param  \Closure(string): never  $fail */
	private static function readString(mixed $value, string $at, \Closure $fail): string
	{
		return is_string($value) ? $value : $fail("`$at` must be a string");
	}


	/** @param  \Closure(string): never  $fail */
	private static function readBool(mixed $value, string $at, \Closure $fail): bool
	{
		return is_bool($value) ? $value : $fail("`$at` must be `true` or `false`");
	}
}
