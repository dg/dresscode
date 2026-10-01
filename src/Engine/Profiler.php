<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use function count, is_array;


/**
 * Where the time of a run goes, for the hidden option `--profile`: the phases of processing a file, the callbacks
 * of every rule, the claims of the gap rules and the analyses. A worker hands over what it measured with every file,
 * the parent adds it up, so the totals do not depend on the number of workers. Times are in nanoseconds.
 * @internal
 */
final class Profiler
{
	public const Schema = 1;
	private const SlowestFiles = 50;

	/** @var array<string, list<int>>  phase => nanoseconds, calls */
	private array $phases = [];

	/** @var array<string, list<int>>  rule => nanoseconds, calls, calls that changed the tree */
	private array $rules = [];

	/** @var array<string, list<int>>  gap rule => nanoseconds, calls of its claims */
	private array $claims = [];

	/** @var list<array<string, int|string>>  every file with the phases of its processing */
	private array $files = [];

	/** @var array<int, int>  process id => its peak memory */
	private array $memory = [];


	public function addPhase(string $phase, int $time, int $calls = 1): void
	{
		self::add($this->phases, $phase, [$time, $calls]);
	}


	public function addRule(string $rule, int $time, bool $mutated): void
	{
		self::add($this->rules, $rule, [$time, 1, (int) $mutated]);
	}


	public function addClaim(string $rule, int $time): void
	{
		self::add($this->claims, $rule, [$time, 1]);
	}


	/** @param array<string, int|string> $file  path, size, time and the phases of its processing */
	public function addFile(array $file): void
	{
		$this->files[] = $file;
	}


	/**
	 * What was measured since the last call, as data for another process, and forgets it.
	 * @return array<string, mixed>
	 */
	public function takeRecords(): array
	{
		$records = [
			'phases' => $this->phases,
			'rules' => $this->rules,
			'claims' => $this->claims,
			'files' => $this->files,
			'memory' => [getmypid() => memory_get_peak_usage()],
		];
		$this->phases = $this->rules = $this->claims = $this->files = [];
		return $records;
	}


	/** @param array<mixed> $records  as `takeRecords()` of another process made them */
	public function merge(array $records): void
	{
		foreach (['phases', 'rules', 'claims'] as $table) {
			foreach (is_array($records[$table] ?? null) ? $records[$table] : [] as $name => $values) {
				self::add($this->$table, (string) $name, array_values(array_map(intval(...), (array) $values)));
			}
		}

		foreach (is_array($records['files'] ?? null) ? $records['files'] : [] as $file) {
			$this->files[] = (array) $file;
		}

		foreach (is_array($records['memory'] ?? null) ? $records['memory'] : [] as $pid => $peak) {
			$this->memory[(int) $pid] = max($this->memory[(int) $pid] ?? 0, (int) $peak);
		}
	}


	/**
	 * The profile of the whole run, the most expensive first; every file with its size and time, in the order
	 * of the paths, for a sample to be drawn from.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$files = $this->files;
		usort($files, fn(array $a, array $b) => $b['time'] <=> $a['time']);
		$times = [];
		foreach ($files as $file) {
			$times[(string) $file['path']] = [(int) $file['size'], (int) $file['time']];
		}

		ksort($times);
		$pid = (int) getmypid();
		$this->memory[$pid] = max($this->memory[$pid] ?? 0, memory_get_peak_usage());
		$byTime = static function (array $table, array $names): array {
			uasort($table, fn(array $a, array $b) => $b[0] <=> $a[0]);
			return array_map(fn(array $values) => array_combine($names, $values), $table);
		};
		return [
			'schema' => self::Schema,
			'files' => count($files),
			'phases' => $byTime($this->phases, ['time', 'calls']),
			'rules' => $byTime($this->rules, ['time', 'calls', 'mutating']),
			'claims' => $byTime($this->claims, ['time', 'calls']),
			'slowestFiles' => array_slice($files, 0, self::SlowestFiles),
			'fileTimes' => $times,
			'peakMemory' => $this->memory,
		];
	}


	/**
	 * @param array<string, list<int>> $table
	 * @param list<int> $values
	 */
	private static function add(array &$table, string $name, array $values): void
	{
		$table[$name] = isset($table[$name])
			? array_map(fn(int $a, int $b) => $a + $b, $table[$name], $values)
			: $values;
	}
}
