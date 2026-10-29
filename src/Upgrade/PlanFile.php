<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\Versions;
use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
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

	/** the step => its kind, the word of the file */
	private const Kinds = [
		FixStep::class => 'fix',
		ComposerStep::class => 'composer',
		UpdateStep::class => 'update',
		ManualStep::class => 'manual',
	];

	/** the keys of a plan */
	private const Keys = ['version', 'toolVersion', 'dataVersions', 'targets', 'paths', 'tests', 'estimate', 'steps'];

	/** the keys of a step that its kind takes, beside `title`, `kind` and `skip` */
	private const StepKeys = ['fix' => ['topics'], 'composer' => ['require', 'update', 'platform'], 'update' => [], 'manual' => ['target']];


	/** The identity of the saved plan, a hash of its text, by which a tool tells one plan from another; null without one. */
	public static function identify(string $root): ?string
	{
		$text = @file_get_contents("$root/" . self::Name); // @ the plan may not be saved
		return $text === false ? null : substr(hash('xxh128', $text), 0, 16);
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
			if (!Target::isPackageName($package)) {
				$fail("`targets.$package` must be `php` or a package as `vendor/name`");
			} elseif (!Versions::isVersion($version)) {
				$fail("`targets.$package` must be a version, as `'8.4'`");
			}

			$targets[] = new Target($package, $version);
		}

		$tests = self::readMap($data['tests'] ?? [], 'tests', $fail);
		if (($unknown = array_diff(array_keys($tests), ['command', 'quick'])) !== []) {
			$fail('`tests.' . reset($unknown) . '` is no key of `tests`, which takes `command` and `quick`');
		}

		foreach ($tests as $key => $command) {
			if (!str_contains($command, '{junit}')) {
				$fail("`tests.$key` must be a command writing its JUnit report where `{junit}` stands");
			}
		}

		$estimate = $data['estimate'] ?? [];
		if (!is_array($estimate) || ($unknown = array_diff(array_keys($estimate), ['unprocessed', 'missingData'])) !== []) {
			$fail(is_array($estimate)
				? '`estimate.' . reset($unknown) . '` is no key of `estimate`, which takes `unprocessed` and `missingData`'
				: '`estimate` must be a map');
		}

		$missingData = [];
		$missing = $estimate['missingData'] ?? [];
		foreach (is_array($missing) ? $missing : $fail('`estimate.missingData` must be a map of lists') as $package => $libraries) {
			$missingData[(string) $package] = self::readList($libraries, "estimate.missingData.$package", $fail);
		}

		$steps = $data['steps'] ?? [];
		if (!is_array($steps) || !array_is_list($steps)) {
			$fail('`steps` must be a list');
		}

		foreach ($steps as $index => $step) {
			$steps[$index] = self::readStep($step, 'steps.' . ($index + 1), $targets, $fail);
		}

		if ($targets === [] || $steps === []) {
			$fail('`targets` and `steps` are expected');
		}

		return new Plan(
			$targets,
			$steps,
			self::readString($data['toolVersion'] ?? '', 'toolVersion', $fail),
			self::readMap($data['dataVersions'] ?? [], 'dataVersions', $fail),
			$tests['command'] ?? null,
			$tests['quick'] ?? null,
			self::readList($data['paths'] ?? [], 'paths', $fail),
			self::readList($estimate['unprocessed'] ?? [], 'estimate.unprocessed', $fail),
			$missingData,
		);
	}


	/** Saves the plan into the root of its project. */
	public static function write(Plan $plan, string $root): void
	{
		$steps = array_map(fn(Step $step) => [...self::encodeStep($step), ...$step->skip ? ['skip' => true] : []], $plan->steps);
		$data = array_filter([
			'version' => self::Version,
			'toolVersion' => $plan->toolVersion,
			'dataVersions' => $plan->dataVersions,
			'targets' => Target::collectVersions($plan->targets),
			'paths' => $plan->paths,
			'tests' => array_filter(['command' => $plan->testCommand, 'quick' => $plan->quickTestCommand]),
			'estimate' => array_filter(['unprocessed' => $plan->unestimated, 'missingData' => $plan->missingData]),
			'steps' => $steps,
		], fn($value) => $value !== []);
		$header = '# dresscode upgrade: the plan for ' . implode(', ', array_map(fn(Target $target) => $target->format(), $plan->targets))
			. "; set `skip: true` to leave a step out\n\n";
		Helpers::replaceFile("$root/" . self::Name, $header . Neon::encode($data, blockMode: true));
	}


	/**
	 * The step as the plan file writes it, `skip` aside.
	 * @return array<string, mixed>
	 */
	public static function encodeStep(Step $step): array
	{
		return [
			'title' => $step->title,
			'kind' => self::Kinds[$step::class],
			...match (true) {
				$step instanceof FixStep => ['topics' => $step->topics],
				$step instanceof ComposerStep => [
					'require' => $step->require,
					...$step->update ? [] : ['update' => false],
					...$step->platform === null ? [] : ['platform' => $step->platform],
				],
				$step instanceof ManualStep => ['target' => $step->target->package],
				default => [],
			},
		];
	}


	/**
	 * @param  list<Target>  $targets  those of the plan, one of which a manual step makes way for
	 * @param  \Closure(string): never  $fail
	 */
	private static function readStep(mixed $step, string $at, array $targets, \Closure $fail): Step
	{
		$kind = is_array($step) ? $step['kind'] ?? null : null;
		if (!is_string($kind) || !isset(self::StepKeys[$kind])) {
			$fail("`$at` must give its `kind`, `fix`, `composer`, `update` or `manual`");
		} elseif (($unknown = array_diff(array_keys($step), ['title', 'kind', 'skip', ...self::StepKeys[$kind]])) !== []) {
			$fail("`$at." . reset($unknown) . "` is no key of a step of the kind `$kind`");
		}

		$title = self::readString($step['title'] ?? $kind, "$at.title", $fail);
		$skip = self::readBool($step['skip'] ?? false, "$at.skip", $fail);
		return match ($kind) {
			'fix' => new FixStep(
				$title,
				self::readList($step['topics'] ?? [], "$at.topics", $fail) ?: $fail("`$at.topics` must name the topics the step fixes"),
				$skip,
			),
			'composer' => new ComposerStep(
				$title,
				self::readConstraints(
					$step['require'] ?? [],
					"$at.require",
					$fail,
					required: !isset($step['platform']),
				),
				self::readBool($step['update'] ?? true, "$at.update", $fail),
				isset($step['platform']) ? self::readVersion($step['platform'], "$at.platform", $fail) : null,
				$skip,
			),
			'update' => new UpdateStep($title, $skip),
			default => new ManualStep(
				$title,
				array_find($targets, fn(Target $target) => $target->package === ($step['target'] ?? null))
				?? $fail("`$at.target` must name a package of `targets`"),
				$skip,
			),
		};
	}


	/**
	 * @param  \Closure(string): never  $fail
	 * @return array<string, string>  package => constraint
	 */
	private static function readConstraints(mixed $value, string $at, \Closure $fail, bool $required): array
	{
		$require = self::readMap($value, $at, $fail);
		if ($require === [] && $required) {
			$fail("`$at` must give the constraints the step writes, or the step its `platform`");
		}

		foreach ($require as $package => $constraint) {
			if (!Target::isPackageName($package)) {
				$fail("`$at.$package` must be `php` or a package as `vendor/name`");
			} elseif (!Versions::isConstraint($constraint)) {
				$fail("`$at.$package` must be a constraint Composer reads, as `^8.4`");
			}
		}

		return $require;
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
	private static function readVersion(mixed $value, string $at, \Closure $fail): string
	{
		return is_string($value) && Versions::isVersion($value) ? $value : $fail("`$at` must be a version, as `'8.4'`");
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
