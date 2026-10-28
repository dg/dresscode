<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\ConfigurationException;
use Nette\IOException;
use Nette\Neon\{Exception as NeonException, Neon};
use Nette\Utils\FileSystem;
use function is_array, is_string;


/**
 * The plan of an upgrade as the file `dresscode-upgrade.neon` writes it, which the team commits, reviews and edits: a
 * step left out with `skip: true`, the topics of a fix narrowed, the commands of the tests given. The one shape of the
 * namespace a user writes.
 * @internal
 */
final class PlanFile
{
	/** the name of the file in the root of the project */
	public const Name = 'dresscode-upgrade.neon';


	/** @throws ConfigurationException  for a file that is no plan */
	public static function read(string $file): Plan
	{
		try {
			$data = Neon::decode(FileSystem::read($file));
		} catch (NeonException|IOException $e) {
			throw new ConfigurationException("The plan `$file` cannot be read: {$e->getMessage()}", previous: $e);
		}

		$fail = fn(string $what) => throw new ConfigurationException("The plan `$file`: $what.");
		if (!is_array($data) || !is_array($data['targets'] ?? null) || !is_array($data['steps'] ?? null)) {
			$fail('`targets` and `steps` are expected');
		}

		$targets = [];
		foreach ($data['targets'] as $package => $version) {
			$targets[] = new Target((string) $package, (string) $version);
		}

		$steps = [];
		foreach ($data['steps'] as $index => $step) {
			$kind = is_array($step) ? StepKind::tryFrom((string) ($step['kind'] ?? '')) : null;
			if ($kind === null) {
				$fail('step ' . ($index + 1) . ' must give its `kind`, `fix`, `composer` or `manual`');
			}

			$steps[] = new Step(
				$kind,
				(string) ($step['title'] ?? $kind->value),
				array_values(array_map(strval(...), (array) ($step['only'] ?? []))),
				array_map(strval(...), (array) ($step['require'] ?? [])),
				($step['update'] ?? true) !== false,
				isset($step['suggest']) ? (string) $step['suggest'] : null,
				(bool) ($step['skip'] ?? false),
			);
		}

		$tests = is_array($data['tests'] ?? null) ? $data['tests'] : [];
		return new Plan(
			$targets,
			$steps,
			(string) ($data['dresscode'] ?? ''),
			array_map(strval(...), is_array($data['data'] ?? null) ? $data['data'] : []),
			is_string($tests['command'] ?? null) ? $tests['command'] : null,
			is_string($tests['quick'] ?? null) ? $tests['quick'] : null,
		);
	}


	public static function write(Plan $plan, string $file): void
	{
		$steps = array_map(fn(Step $step) => array_filter([
			'title' => $step->title,
			'kind' => $step->kind->value,
			'only' => $step->only,
			'require' => $step->require,
			'update' => $step->update ? null : false,
			'suggest' => $step->suggest,
			'skip' => $step->skip ?: null,
		], fn($value) => $value !== null && $value !== []), $plan->steps);
		$data = array_filter([
			'dresscode' => $plan->dresscode,
			'data' => $plan->data,
			'targets' => array_column(array_map(fn(Target $target) => [$target->package, $target->version], $plan->targets), 1, 0),
			'tests' => array_filter(['command' => $plan->testCommand, 'quick' => $plan->quickTestCommand]),
			'steps' => $steps,
		], fn($value) => $value !== []);
		$header = '# dresscode upgrade: the plan for ' . implode(', ', array_map(fn(Target $target) => $target->format(), $plan->targets))
			. "; a step goes with `skip: true`, and `dresscode upgrade --continue` runs what is left\n\n";
		FileSystem::write($file, $header . Neon::encode($data, blockMode: true));
	}
}
