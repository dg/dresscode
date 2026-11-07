<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Engine\Helpers;
use DressCode\{Risk, Violation};
use DressCode\Upgrade\{Postponement, Task};
use Nette\CommandLine\Console;
use function count;


/**
 * Writes the places an upgrade leaves for a human, each with its message and, for one whose fix waits, what it waits
 * for, and the comments that only put one off.
 * @internal
 */
final readonly class TasksPrinter
{
	public function __construct(
		/** @var array<string, list<Task>>  topic => its tasks */
		private array $tasks,
		/** @var list<Postponement> */
		private array $postponed = [],
		/** @var array<string, string>  the files the fixes could not process, relative to the root => why */
		private array $unprocessed = [],
		/** the root the paths are relative to, whose files show the lines of a task; none shows none */
		private ?string $root = null,
	) {
	}


	public function print(Console $console): string
	{
		$tasks = array_merge(...array_values($this->tasks));
		$out = '';
		if ($tasks !== []) {
			$out .= Markup::formatLine($console, 'Manual', Helpers::formatCount(count($tasks), 'task') . ' the fixes left') . "\n";
			$files = [];
			foreach ($tasks as $task) {
				$violation = $task->violation;
				$out .= "  $task->path:$violation->line  " . Markup::highlightCode($console, $violation->message)
					. '  ' . $console->color('gray', "[$violation->decision]") . "\n"
					. ($violation->risk === null ? '' : '    ' . Markup::formatAside($console, self::formatRisk($violation)) . "\n");
				if ($this->root !== null) {
					$files[$task->path] ??= explode("\n", (string) @file_get_contents("$this->root/$task->path")); // @ a file gone meanwhile shows no lines
					foreach (self::selectLines($files[$task->path], $violation->line) as $code) {
						$out .= '      ' . Markup::highlightCode($console, Violation::formatCode($code)) . "\n";
					}
				}
			}
		}

		if ($this->postponed !== []) {
			$out .= Markup::formatLine(
				$console,
				'Exceptions',
				Helpers::formatCount(count($this->postponed), 'suppression')
				. ' giving no reason, so not accepted yet: accept each with ` -- <reason>`',
			) . "\n";
			foreach ($this->postponed as $postponement) {
				$out .= "  $postponement->path:$postponement->line  $postponement->comment\n";
			}
		}

		if ($this->unprocessed !== []) {
			$out .= Markup::formatLine(
				$console,
				'Unverified',
				Helpers::formatCount(count($this->unprocessed), 'file') . ' the fixes could not process, left as they are',
			) . "\n";
			foreach ($this->unprocessed as $path => $why) {
				$out .= "  $path  " . Markup::highlightCode($console, (string) strtok($why, "\n")) . "\n";
			}
		}

		return $out;
	}


	/**
	 * The line of a task with the one above and the one below, a blank one left out, without the indentation they share.
	 * @param  list<string>  $lines  of the file
	 * @return list<string>
	 */
	private static function selectLines(array $lines, int $line): array
	{
		$around = array_slice($lines, max(0, $line - 2), $line === 1 ? 2 : 3);
		return Markup::stripIndentation(array_values(array_filter(array_map(rtrim(...), $around), fn(string $code) => $code !== '')));
	}


	/** What the fix of a risky place waits for, and how to give it. */
	private static function formatRisk(Violation $violation): string
	{
		return match ($violation->risk) {
			Risk::TypeUnknown => 'its fix needs the type of the expression, which the analysis does not know; where the types are off, `--types` turns them on',
			Risk::NameUncertain => 'its fix needs to know which function the name calls, which a declaration elsewhere could change; fix it by hand, or list what the namespaces declare under `namespaces` with `nameResolution: certain`',
			default => 'its fix is risky' . ($violation->because === null ? '' : ", as $violation->because")
				. "; once you checked it, `fixRisky: [$violation->decision]` allows it",
		};
	}


	/**
	 * The tasks as the data the format `json` writes.
	 * @return array{
	 *     tasks: list<array<string, mixed>>,
	 *     exceptions: list<array{file: string, line: int, comment: string}>,
	 *     unverified: list<array{file: string, message: string}>,
	 * }
	 */
	public function toArray(): array
	{
		$tasks = [];
		foreach ($this->tasks as $topic => $topicTasks) {
			foreach ($topicTasks as $task) {
				$tasks[] = [
					'topic' => $topic,
					'file' => $task->path,
					'line' => $task->violation->line,
					'decision' => $task->violation->decision,
					'message' => $task->violation->message,
					'fixable' => $task->violation->fixable,
					'risk' => $task->violation->risk?->value,
					'because' => $task->violation->because,
				];
			}
		}

		return [
			'tasks' => $tasks,
			'exceptions' => array_map(
				fn(Postponement $postponement) => [
					'file' => $postponement->path,
					'line' => $postponement->line,
					'comment' => $postponement->comment,
				],
				$this->postponed,
			),
			'unverified' => self::listUnverified($this->unprocessed),
		];
	}


	/**
	 * The files a check could not process as the JSON documents write them.
	 * @param  array<string, string>  $unprocessed  file => why
	 * @return list<array{file: string, message: string}>
	 */
	public static function listUnverified(array $unprocessed): array
	{
		return array_map(fn(string $path, string $why) => ['file' => $path, 'message' => $why], array_keys($unprocessed), $unprocessed);
	}
}
