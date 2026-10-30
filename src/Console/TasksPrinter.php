<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Upgrade\Task;
use Nette\CommandLine\Console;
use function count;


/**
 * Writes the places an upgrade leaves for a human, each with its message, and the comments that only put one off.
 * @internal
 */
final readonly class TasksPrinter
{
	/** the version of the document the format `json` writes for `--tasks` */
	public const Version = 1;


	public function __construct(
		/** @var array<string, list<Task>>  topic => its tasks */
		private array $tasks,
		/** @var list<array{path: string, line: int, comment: string}>  the suppressions giving no reason */
		private array $postponed = [],
	) {
	}


	public function print(Console $console): string
	{
		$label = fn(string $label) => $console->color('gray', str_pad($label, 11));
		$tasks = array_merge(...array_values($this->tasks));
		$out = '';
		if ($tasks !== []) {
			$out .= $label('For you') . Markup::formatCount(count($tasks), 'place') . " the fixes left\n";
			foreach ($tasks as $task) {
				$violation = $task->violation;
				$out .= "  $task->path:$violation->line  $violation->message  " . $console->color('gray', "[$violation->decision]") . "\n";
			}
		}

		if ($this->postponed !== []) {
			$out .= $label('Postponed') . Markup::formatCount(count($this->postponed), 'suppression')
				. " giving no reason, which put a task off: confirm it with ` -- <reason>`\n";
			foreach ($this->postponed as ['path' => $path, 'line' => $line, 'comment' => $comment]) {
				$out .= "  $path:$line  $comment\n";
			}
		}

		return $out;
	}


	/**
	 * The tasks as the data the format `json` writes.
	 * @return array{tasks: list<array<string, mixed>>, postponed: list<array{path: string, line: int, comment: string}>}
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
				];
			}
		}

		return ['tasks' => $tasks, 'postponed' => $this->postponed];
	}
}
