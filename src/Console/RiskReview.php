<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{RuleRegistry, RunnerFactory};
use DressCode\Engine\{Diff, FileSummary, Runner, RunResult};
use DressCode\Violation;
use Nette\CommandLine\Console;
use Nette\Utils\FileSystem;
use function in_array;


/**
 * What `fix --review` asks after the fix: every risky fix the run left, one occurrence at a time with the diff it would
 * make, and the fixes accepted are written. An occurrence is taken on the text the fix wrote, where its fingerprint is
 * the one the run gives it, so the consent reaches that occurrence and no other.
 * @internal
 */
final class RiskReview
{
	/** @var array<string, true>  rules whose risky fixes are all accepted, `a` having been answered */
	private array $acceptedRules = [];

	private int $made = 0;


	public function __construct(
		private readonly Runner $runner,
		private readonly Console $console,
		/** @var resource */
		private $input,
		private readonly string $root,
		private readonly RuleRegistry $registry,
	) {
	}


	/** Goes through the files of the run a risky fix waits in, and says how many fixes it made. */
	public function review(RunResult $result): int
	{
		foreach ($result->files as $file) {
			if (array_filter($file->remaining, fn(Violation $v) => $v->refused) && !$this->reviewFile($file)) {
				break;
			}
		}

		return $this->made;
	}


	/** Asks about the risky fixes of the file and writes those accepted; false when the answer was to stop. */
	private function reviewFile(FileSummary $file): bool
	{
		$path = RunnerFactory::toAbsolutePath($file->path, $this->root);
		$original = $text = FileSystem::read($path);
		$declined = []; // the fingerprints of the occurrences answered
		$going = true;
		while ($going) {
			$next = array_find(
				$this->runner->processFile($path, $text)->remaining,
				fn(Violation $v) => $v->refused && !isset($declined[$v->fingerprint]),
			);
			if ($next === null) {
				break;
			}

			$proposed = $this->runner->processFile($path, $text, [$next->fingerprint => true])->output;
			$answer = $proposed === null || $proposed === $text
				? 'n'
				: (isset($this->acceptedRules[$next->ruleName]) ? 'y' : $this->ask($file->path, $next, $text, $proposed));
			// an occurrence is asked about once, so a fix that leaves the same one behind cannot ask for ever
			$declined[$next->fingerprint] = true;
			if ($answer === 'y' || $answer === 'a') {
				assert($proposed !== null);
				$text = $proposed;
				$this->made++;
				if ($answer === 'a') {
					$this->acceptedRules[$next->ruleName] = true;
				}
			} else {
				$going = $answer !== 'q';
			}
		}

		if ($text !== $original) {
			FileSystem::write($path, $text);
		}

		return $going;
	}


	/** Shows the risky fix of the occurrence and reads the answer, `q` at the end of the input. */
	private function ask(string $path, Violation $violation, string $text, string $proposed): string
	{
		$this->console->write(
			"\n" . $this->console->color('white', "$path:$violation->line") . '  '
			. Markup::highlightCode($this->console, $violation->message) . '  '
			. $this->console->color('gray', Markup::formatRuleName($this->console, $violation->ruleName, $this->registry->getRuleUrl($violation->ruleName))) . "\n"
			. ($violation->because === null ? '' : Markup::highlightCode($this->console, $violation->because, 'gray') . "\n")
			. Markup::highlightDiff($this->console, Diff::unified($text, $proposed, $path)),
		);
		do {
			$this->console->write('Apply this risky fix? [y,n,a,q] ');
			$line = fgets($this->input);
			$answer = $line === false ? 'q' : strtolower(trim($line));
		} while (!in_array($answer, ['y', 'n', 'a', 'q'], true));

		return $answer;
	}
}
