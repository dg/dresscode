<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\ProcessRunner;
use Nette\Utils\FileSystem;


/**
 * Runs the command of the tests as a process and reads the JUnit report it writes: a command whose report holds no
 * test, or that ends with an exit code a runner of tests does not end with for its failing tests, crashed, which its
 * report does not show.
 * @internal
 */
final readonly class ProcessTestRunner implements TestRunner
{
	/** how long the tests may run */
	private const Timeout = 3600;

	/** the exit codes PHPUnit and Nette Tester end with where tests failed or threw, 0 aside */
	private const FailingExitCodes = [1, 2];


	public function run(string $command, string $root): TestResults
	{
		$file = sys_get_temp_dir() . '/dresscode-junit-' . bin2hex(random_bytes(6)) . '.xml';
		[$exit, $output] = ProcessRunner::run(str_replace(TestResults::Placeholder, escapeshellarg($file), $command), $root, self::Timeout);
		$results = is_file($file) ? TestResults::fromJunit(FileSystem::read($file), $root) : null;
		@unlink($file); // @ a command may have written none
		$lines = array_filter(array_map(trim(...), preg_split('~\R~', $output) ?: []));
		$crash = match (true) {
			$exit === null => 'the command of the tests did not end within ' . self::Timeout . ' s',
			$results === null => 'the command of the tests wrote no JUnit report where `' . TestResults::Placeholder . '` stands',
			$results->tests === [] => 'the report of the tests holds no test',
			$exit === 0, in_array($exit, self::FailingExitCodes, true) && $results->countNotPassed() > 0 => null,
			default => "the command of the tests ended with exit code $exit" . ($lines === [] ? '' : ': ' . end($lines)),
		};
		return new TestResults($results->tests ?? [], $crash, time());
	}
}
