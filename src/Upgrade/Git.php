<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\ProcessRunner;


/**
 * The git a run of a plan needs and nothing more: where its repository is, and what the working tree has changed.
 * @internal
 */
final readonly class Git implements VersionControl
{
	/** how long git may take */
	private const Timeout = 120;


	public function __construct(
		private string $root,
	) {
	}


	/** The directory of the repository of the project, a linked worktree having its own; null outside one. */
	public function findDirectory(): ?string
	{
		[$exit, $output] = $this->run(['rev-parse', '--absolute-git-dir'], errors: false);
		return $exit === 0 && !$this->ignoresRoot() ? trim($output) : null;
	}


	/**
	 * Whether the tracked files have changes, the files given aside.
	 * @param  list<string>  $except  relative to the root
	 */
	public function readStatus(array $except = []): WorkingTree
	{
		$excludes = array_map(fn(string $path) => ":(exclude,literal)$path", $except);
		[$exit, $status] = $this->run(['status', '--porcelain', '--untracked-files=no', '--', '.', ...$excludes]);
		return match (true) {
			$exit !== 0, $this->ignoresRoot() => WorkingTree::Unavailable,
			trim($status) === '' => WorkingTree::Clean,
			default => WorkingTree::Dirty,
		};
	}


	/** Whether the repository git finds above ignores the root, which then lies in no repository of its own. */
	private function ignoresRoot(): bool
	{
		return $this->run(['check-ignore', '--quiet', '.'])[0] === 0;
	}


	/**
	 * @param  list<string>  $arguments
	 * @return array{?int, string}
	 */
	private function run(array $arguments, bool $errors = true): array
	{
		return ProcessRunner::run(['git', ...$arguments], $this->root, self::Timeout, errors: $errors);
	}
}
