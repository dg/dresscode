<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use Nette\Utils\FileSystem;
use function is_array;
use const JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR;


/**
 * The results of the tests of the project, test by test, read from the JUnit file its command writes: the identity of a
 * test is its `classname` and `name`, a class and a method with its data set for PHPUnit, a file with its arguments for
 * Nette Tester. A run against a baseline tells the tests failing now that did not before.
 * @internal
 */
final readonly class TestResults
{
	/** how long the tests may run */
	private const Timeout = 3600;


	public function __construct(
		/** @var array<string, bool>  the identity of a test => whether it passed */
		public array $tests,
	) {
	}


	/**
	 * Runs the command of the project in its root, `{junit}` standing for the file it writes; null where it wrote none,
	 * having not run or not reported.
	 */
	public static function collect(string $command, string $root): ?self
	{
		$file = sys_get_temp_dir() . '/dresscode-junit-' . bin2hex(random_bytes(6)) . '.xml';
		ProcessRunner::run(str_replace('{junit}', escapeshellarg($file), $command), $root, self::Timeout);
		if (!is_file($file)) {
			return null;
		}

		$results = self::fromJunit(FileSystem::read($file), $root);
		FileSystem::delete($file);
		return $results;
	}


	public static function fromJunit(string $xml, string $root): self
	{
		$document = @simplexml_load_string($xml); // @ a broken file is no results
		$tests = [];
		foreach (($document === false ? null : $document->xpath('//testcase')) ?: [] as $case) {
			$class = str_replace(['\\', strtr($root, '\\', '/') . '/'], ['/', ''], (string) $case['classname']);
			$tests["$class::{$case['name']}"] = !isset($case->failure) && !isset($case->error);
		}

		return new self($tests);
	}


	/**
	 * The tests failing now that passed in the baseline or were not there.
	 * @return list<string>
	 */
	public function findNewFailures(self $baseline): array
	{
		$failures = [];
		foreach ($this->tests as $test => $passed) {
			if (!$passed && ($baseline->tests[$test] ?? true)) {
				$failures[] = $test;
			}
		}

		return $failures;
	}


	public static function fromFile(string $file): ?self
	{
		$tests = is_file($file) ? json_decode(FileSystem::read($file), associative: true) : null;
		return is_array($tests) ? new self($tests) : null;
	}


	public function save(string $file): void
	{
		FileSystem::write($file, json_encode($this->tests, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}
}
