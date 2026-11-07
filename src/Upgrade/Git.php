<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Engine\ProcessRunner;
use Nette\Utils\FileSystem;


/**
 * The git a run of a plan needs and nothing more: what the working tree has changed, which files it ignores, and
 * a commit of the files of a topic.
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


	/**
	 * The files of those given that git tracks or would add, those it ignores aside.
	 * @param  list<string>  $paths  relative to the root
	 * @return list<string>
	 * @throws GitException
	 */
	public function listUnignored(array $paths): array
	{
		$arguments = ['ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', ...self::toPathspecs($paths)];
		$output = $this->runOrThrow($arguments, errors: false);
		return array_values(array_intersect($paths, explode("\0", $output)));
	}


	/**
	 * Commits the files with the texts given through an index of its own, so that the working tree stays as it is and the
	 * index changes only in those files, which it then takes as committed; the hooks of the repository run as for any
	 * commit.
	 * @param  array<string, string>  $files  relative to the root => text
	 * @throws GitException  where git refuses, a hook of the repository among the reasons
	 */
	public function commit(array $files, string $subject, string $body): void
	{
		$pathspecs = self::toPathspecs(array_map(strval(...), array_keys($files)));
		$index = sys_get_temp_dir() . '/dresscode-index-' . bin2hex(random_bytes(8));
		$env = ['GIT_INDEX_FILE' => $index];
		try {
			if ($this->run(['rev-parse', '--verify', '--quiet', 'HEAD'])[0] === 0) {
				$this->runOrThrow(['read-tree', 'HEAD'], env: $env);
			}

			// a file keeps the mode it has, and its text is cleaned by the attributes as `git add` would clean it; the index
			// names it from the top of the repository, which may lie above the root
			$modes = [];
			$staged = $this->runOrThrow(['ls-files', '--stage', '--full-name', '-z', '--', ...$pathspecs], env: $env, errors: false);
			foreach (array_filter(explode("\0", $staged)) as $entry) {
				[$meta, $name] = explode("\t", $entry, 2) + [1 => ''];
				$modes[$name] = explode(' ', $meta)[0];
			}

			$prefix = trim($this->runOrThrow(['rev-parse', '--show-prefix'], errors: false));
			$entries = '';
			foreach ($files as $path => $text) {
				$name = FileSystem::unixSlashes(FileSystem::normalizePath($prefix . $path));
				$blob = trim($this->runOrThrow(['hash-object', '-w', '--stdin', "--path=$path"], $text, errors: false));
				$entries .= ($modes[$name] ?? '100644') . " $blob\t$name\0";
			}

			$this->runOrThrow(['update-index', '-z', '--index-info'], input: $entries, env: $env);
			$this->runOrThrow(['commit', '--quiet', '-m', $subject, '-m', $body], env: $env);
		} finally {
			@unlink($index); // @ git may not have written it
			@unlink("$index.lock"); // @ left only by a git that failed
		}

		$this->runOrThrow(['reset', '--quiet', '--', ...$pathspecs]);
	}


	/** Whether the repository git finds above ignores the root, which then lies in no repository of its own. */
	private function ignoresRoot(): bool
	{
		return $this->run(['check-ignore', '--quiet', '.'])[0] === 0;
	}


	/**
	 * @param  list<string>  $paths
	 * @return list<string>
	 */
	private static function toPathspecs(array $paths): array
	{
		return array_map(fn(string $path) => ":(literal)$path", $paths);
	}


	/**
	 * The output of git, and where it fails the reason, which only an output with the errors tells.
	 * @param  list<string>  $arguments
	 * @param  array<string, string>  $env
	 * @throws GitException
	 */
	private function runOrThrow(array $arguments, ?string $input = null, array $env = [], bool $errors = true): string
	{
		[$exit, $output] = $this->run($arguments, $input, $env, $errors);
		if ($exit !== 0) {
			$lines = array_filter(array_map(trim(...), explode("\n", $output)));
			$reason = end($lines) ?: ($exit === null ? 'it did not end in time' : "exit code $exit");
			throw new GitException("`git $arguments[0]` failed: $reason");
		}

		return $output;
	}


	/**
	 * @param  list<string>  $arguments
	 * @param  array<string, string>  $env
	 * @return array{?int, string}
	 */
	private function run(array $arguments, ?string $input = null, array $env = [], bool $errors = true): array
	{
		return ProcessRunner::run(['git', ...$arguments], $this->root, self::Timeout, $input, $errors, $env);
	}
}
