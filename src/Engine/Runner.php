<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{ConvergenceException, FileResult, Reporter, RuleException};
use Nette\Utils\{FileSystem, Finder};
use function count, sprintf, strlen;


/**
 * Runs the file processor over the files of a project. Paths are relative to the root, with slashes.
 * @internal
 */
final readonly class Runner
{
	private string $root;
	private FileProcessors $processors;


	public function __construct(
		FileProcessor|FileProcessors $processors,
		string $root,
		/** @var list<string> patterns of paths left out */
		private array $excludePaths = [],
		/** @var list<string> */
		private array $fileExtensions = ['php'],
		/** @var ?\Closure(string $content, string $path): bool files left out by their content */
		private ?\Closure $skipWhen = null,
		/** violations left unreported */
		private ?Baseline $baseline = null,
		/** the run is narrowed to some of the decisions, so it says nothing about the baseline entries of the others */
		private bool $narrowed = false,
	) {
		$this->root = Helpers::canonicalizePath($root);
		$this->processors = $processors instanceof FileProcessor ? FileProcessors::of($processors) : $processors;
	}


	/**
	 * Processes the files; a file whose rules fail is reported as a failure and the run goes on. A file is reported
	 * as soon as the files before it are, and the run keeps its result without the texts, so that a large tree is
	 * never held in memory as a whole; the texts are what a reporter sees in `reportFile()`.
	 * @param list<string> $files  as `findFiles()` returned them
	 * @param ?\Closure(int, array<string, float>): void $onProgress  files done and the paths in progress
	 */
	public function run(
		array $files,
		bool $fix,
		Reporter $reporter,
		?\Closure $onProgress = null,
		?int $maxWarnings = null,
	): RunResult
	{
		$reporter->start(new RunInfo($this->root, $fix, count($files)));
		/** @var array<string, FileResult> $ready the finished files */
		$ready = [];
		$order = [];
		foreach ($files as $path) {
			$code = $this->read($path);
			if ($code !== null && $this->skipWhen && ($this->skipWhen)($code, $path)) {
				continue;
			}

			$order[] = $path;
		}

		foreach ($order as $path) { // a configuration the rules do not fit fails before any file
			$this->processors->get($path);
		}

		$ordered = [];
		$scope = []; // the files the run can say something about to the baseline, and by which decisions
		$report = function () use (&$ready, &$ordered, &$scope, $order, $reporter): void {
			for ($next = count($ordered); isset($order[$next], $ready[$order[$next]]); $next++) {
				$path = $order[$next];
				$result = $ready[$path];
				unset($ready[$path]);
				if ($this->baseline !== null && $result->syntaxError === null && $result->failure === null) {
					$scope[$path] = $this->narrowed ? $this->processors->get($path)->getReportedDecisions() : null;
				}

				$reporter->reportFile($result);
				$ordered[] = FileSummary::of($result);
			}
		};

		foreach ($this->processPending($order, $fix, $onProgress) as $path => $result) {
			$ready[$path] = $result;
			$report();
		}

		if ($onProgress !== null) {
			$onProgress(count($files), []); // the whole scope is done, whatever was skipped along the way
		}

		$unmatched = $this->baseline?->countUnmatched($scope) ?? 0;
		$result = new RunResult(
			$ordered,
			$fix,
			baselined: $this->baseline?->countMatched() ?? 0,
			warnings: $unmatched ? [sprintf(
				'%d %s of the baseline no longer %s a violation; regenerate it with `dresscode baseline`.',
				$unmatched,
				$unmatched === 1 ? 'entry' : 'entries',
				$unmatched === 1 ? 'matches' : 'match',
			)] : [],
			maxWarnings: $maxWarnings,
		);
		$reporter->finish($result);
		return $result;
	}


	/**
	 * The results of the files in the order they are done, each file read when its turn comes.
	 * @param  list<string>  $paths
	 * @param  ?\Closure(int, array<string, float>): void  $onProgress
	 * @return \Generator<string, FileResult>
	 */
	private function processPending(array $paths, bool $fix, ?\Closure $onProgress): \Generator
	{
		$done = 0;
		$collector = new CycleCollector;
		try {
			foreach ($paths as $path) {
				if ($onProgress !== null) {
					$onProgress($done++, [$path => microtime(as_float: true)]);
				}

				$result = $this->processPath($path, $fix);
				$collector->afterFile();
				yield $path => $result;
			}
		} finally {
			$collector->stop();
		}
	}


	/**
	 * Processes one file of the project: a failing rule or a file that cannot be read or written is a failed result,
	 * fix writes a changed file back.
	 * @param  ?string  $code  the text standing for the file, which is then not read
	 */
	public function processPath(string $path, bool $fix, ?string $code = null): FileResult
	{
		$path = $this->relativize($path);
		$code ??= $this->read($path);
		if ($code === null) {
			return self::createUnreadable($path);
		}

		try {
			$result = $this->processCode($path, $code);
		} catch (RuleException $e) {
			$result = new FileResult($path, $code, output: null, failure: $e->getMessage());
		} catch (ConvergenceException $e) {
			$failure = $e->getMessage() . ($e->diff === '' ? '' : "\n$e->diff");
			$result = new FileResult($path, $code, output: null, failure: $failure, failureDocs: ConvergenceException::Docs);
		}

		if ($fix && $result->changed) {
			if ($this->read($path) !== $code) { // saved meanwhile by someone else, whose text wins
				return new FileResult($path, $code, output: null, failure: 'The file changed while it was being fixed, so it was not written; run `fix` again.');
			}

			if (!Helpers::writeFile($this->toAbsolute($path), (string) $result->output)) {
				return new FileResult($path, $code, output: null, failure: "Cannot write file `$path`.");
			}

			$result->markWritten();
		}

		return $result;
	}


	/** The content of the file, null when it cannot be read. */
	private function read(string $path): ?string
	{
		$code = @file_get_contents($this->toAbsolute($path)); // @ the failure is the result of the file
		return $code === false ? null : $code;
	}


	private static function createUnreadable(string $path): FileResult
	{
		return new FileResult($path, '', output: null, failure: "Cannot read file `$path`.");
	}


	/**
	 * Processes a text that stands for the file at the path, with the rules that apply to it; nothing is written.
	 * @throws RuleException|ConvergenceException
	 */
	public function processCode(string $path, string $code): FileResult
	{
		$path = $this->relativize($path);
		$result = $this->processors->get($path)->process($path, $code);
		$this->baseline?->markMatched($result->path, $result->baselined);
		return $result;
	}


	/**
	 * Indexes of the overrides that apply to the file.
	 * @return list<int>
	 */
	public function findOverridesFor(string $path): array
	{
		return $this->processors->findOverrides($this->relativize($path));
	}


	/**
	 * Files under the paths with one of the extensions, minus the excluded ones; an explicitly given file
	 * is taken as is, unless `$skipExcluded` lets the excluded paths leave it out like a found one, which
	 * is what a hook or an editor naming every file it touches wants; a file outside the root has no path
	 * the patterns could match. Sorted, relative to the root.
	 * @param  list<string>  $paths
	 * @return list<string>
	 */
	public function findFiles(array $paths, bool $skipExcluded = false): array
	{
		$files = [];
		foreach ($paths as $path) {
			$path = $this->relativize($path);
			$absolute = $this->toAbsolute($path);
			if (is_file($absolute)) {
				if (!$skipExcluded || FileSystem::isAbsolute($path) || !Helpers::matchesAny($this->excludePaths, $path)) {
					$files[$path] = true;
				}
			} elseif (is_dir($absolute)) {
				$finder = Finder::findFiles(array_map(fn($ext) => "*.$ext", $this->fileExtensions))
					->from($absolute)
					->descentFilter(fn(\SplFileInfo $dir) => !Helpers::matchesAny($this->excludePaths, $this->relativize($dir->getPathname())));
				foreach ($finder as $file) {
					$relative = $this->relativize($file->getPathname());
					if (!Helpers::matchesAny($this->excludePaths, $relative)) {
						$files[$relative] = true;
					}
				}
			} else {
				throw new \RuntimeException("Path `$path` does not exist.");
			}
		}

		$files = array_keys($files);
		sort($files, SORT_STRING);
		return $files;
	}


	/**
	 * The paths named within the configured ones: a directory holding some of those stands for them, anything else
	 * for itself, since whoever names a path outside them means it.
	 * @param  list<string>  $paths
	 * @param  list<string>  $configured
	 * @return list<string>
	 */
	public function narrowPaths(array $paths, array $configured): array
	{
		$configured = array_map($this->relativize(...), $configured);
		$narrowed = [];
		foreach ($paths as $path) {
			$relative = $this->relativize($path);
			$held = in_array($relative, $configured, true)
				? []
				: array_filter($configured, fn(string $inner) => $relative === '' || str_starts_with("$inner/", "$relative/"));
			array_push($narrowed, ...($held ?: [$path]));
		}

		return array_values(array_unique($narrowed));
	}


	/**
	 * A path outside the root stays absolute, a relative one is under the root.
	 */
	public function toAbsolute(string $path): string
	{
		return match (true) {
			$path === '' => $this->root,
			FileSystem::isAbsolute($path) => $path,
			default => $this->root . '/' . $path,
		};
	}


	/**
	 * Path with slashes, relative to the root when it lies under it.
	 */
	public function relativize(string $path): string
	{
		$path = Helpers::canonicalizePath($path);
		if ($path === $this->root) {
			return '';
		} elseif (str_starts_with($path, $this->root . '/')) {
			$path = substr($path, strlen($this->root) + 1);
		}

		return implode('/', array_filter(explode('/', $path), fn(string $segment) => $segment !== '.')); // "./a" and "." would match the ".*" exclusion
	}
}
