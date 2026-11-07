<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function count, in_array, is_array, is_string;
use const JSON_PRETTY_PRINT, JSON_THROW_ON_ERROR;


/**
 * The results of the tests of the project, test by test, read from the JUnit file its command writes: the identity of a
 * test is its `classname` and `name`, a class and a method with its data set for PHPUnit, a file with its arguments for
 * Nette Tester. A run against a baseline tells the tests not passing now that passed before, and those gone.
 * @internal
 */
final readonly class TestResults
{
	/** what stands in a command of the tests for the JUnit file it writes */
	public const Placeholder = '{junit}';


	public function __construct(
		/** @var array<string, TestStatus>  the identity of a test => how it ended */
		public array $tests,
		/** why the results are not to be trusted: the command crashed, did not end in time or wrote no report of tests */
		public ?string $crash = null,
		/** when the tests ran, a Unix timestamp; null for results read from a report */
		public ?int $time = null,
	) {
	}


	/** Whether the command writes its results where the JUnit file it is given stands. */
	public static function hasJunitPlaceholder(string $command): bool
	{
		return str_contains($command, self::Placeholder);
	}


	/**
	 * The results the JUnit report holds, a test repeated under one identity passing only where every one passed; null
	 * for a text that is no report.
	 */
	public static function fromJunit(string $xml, string $root): ?self
	{
		$document = @simplexml_load_string($xml); // @ a broken file is no report
		if ($document === false || !in_array($document->getName(), ['testsuites', 'testsuite'], true)) {
			return null;
		}

		$tests = [];
		$rank = array_flip(array_column(TestStatus::cases(), 'value'));
		foreach ($document->xpath('//testcase') ?: [] as $case) {
			$class = str_replace(['\\', strtr($root, '\\', '/') . '/'], ['/', ''], (string) $case['classname']);
			$id = "$class::{$case['name']}";
			$status = match (true) {
				isset($case->error) => TestStatus::Error,
				isset($case->failure) => TestStatus::Failed,
				isset($case->skipped) => TestStatus::Skipped,
				default => TestStatus::Passed,
			};
			$tests[$id] = isset($tests[$id]) && $rank[$tests[$id]->value] > $rank[$status->value] ? $tests[$id] : $status;
		}

		return new self($tests);
	}


	/** The results a run kept; null where there are none, or of a shape no longer read. */
	public static function fromFile(string $file): ?self
	{
		$tests = is_file($file) ? json_decode(FileSystem::read($file), associative: true) : null;
		$tests = is_array($tests) ? array_map(fn($status) => is_string($status) ? TestStatus::tryFrom($status) : null, $tests) : [null];
		return in_array(null, $tests, true) ? null : new self($tests, time: (int) filemtime($file));
	}


	public function save(string $file): void
	{
		Helpers::replaceFile(
			$file,
			json_encode(array_map(fn(TestStatus $status) => $status->value, $this->tests), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
		);
	}


	/**
	 * The tests not passing now that passed in the baseline or were not there, each said how it ended where it did not
	 * just fail, and those of the baseline that passed and are gone.
	 * @return list<string>
	 */
	public function findNewFailures(self $baseline): array
	{
		$failures = [];
		foreach ($this->tests as $test => $status) {
			if ($status !== TestStatus::Passed && ($baseline->tests[$test] ?? TestStatus::Passed) === TestStatus::Passed) {
				$failures[] = $test . ($status === TestStatus::Failed ? '' : " ($status->value)");
			}
		}

		$passed = array_filter($baseline->tests, fn(TestStatus $status) => $status === TestStatus::Passed);
		foreach (array_keys(array_diff_key($passed, $this->tests)) as $test) {
			$failures[] = "$test (gone)";
		}

		return $failures;
	}


	/** How many of the tests did not pass: failed, threw or were skipped. */
	public function countNotPassed(): int
	{
		return count(array_filter($this->tests, fn(TestStatus $status) => $status !== TestStatus::Passed));
	}
}
