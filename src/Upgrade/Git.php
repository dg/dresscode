<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * The git a run of a plan needs and nothing more: whether the working tree has changes of its own, and a commit of the
 * files of a topic.
 * @internal
 */
final readonly class Git
{
	/** how long git may take */
	private const Timeout = 120;


	public function __construct(
		private string $root,
	) {
	}


	/** Whether the tracked files have changes of their own, the saved plan aside; never outside a repository. */
	public function hasOwnChanges(): bool
	{
		[$exit, $status] = $this->run(['status', '--porcelain', '--untracked-files=no', '--', '.', ':(exclude)' . PlanFile::Name]);
		return $exit === 0 && trim($status) !== '';
	}


	/**
	 * Commits the files as they are, untracked ones among them.
	 * @param  list<string>  $paths  relative to the root
	 */
	public function commit(array $paths, string $subject, string $body): void
	{
		$this->run(['add', '--', ...$paths]);
		$this->run(['commit', '-m', $subject, '-m', $body, '--', ...$paths]);
	}


	/**
	 * @param  list<string>  $arguments
	 * @return array{?int, string}
	 */
	private function run(array $arguments): array
	{
		return ProcessRunner::run(['git', ...$arguments], $this->root, self::Timeout);
	}
}
