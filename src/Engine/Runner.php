<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{ConvergenceException, FileResult, Reporter, RuleException};
use Nette\Utils\{FileSystem, Finder};
use function count, is_string, sprintf, strlen;


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
		/** contents known to be clean, skipped without processing */
		private ?ResultCache $cache = null,
		/** the run is narrowed to some of the decisions, so it says nothing about the baseline entries of the others */
		private bool $narrowed = false,
		/** @var ?\Closure(): void  fills the cache the workers share, which those started together would all write at once */
		public ?\Closure $warmUp = null,
		public TypeAnalysisStatus $typeAnalysis = TypeAnalysisStatus::Unavailable,
		/** the configuration lists functions or constants the namespaces declare */
		public bool $namespacesListed = false,
	) {
		$this->root = Helpers::canonicalizePath($root);
		$this->processors = $processors instanceof FileProcessor ? FileProcessors::of($processors) : $processors;
	}


	/**
	 * Processes the files; a file whose rules fail is reported as a failure and the run goes on. A file is reported
	 * as soon as the files before it are, and the run keeps its result without the texts, so that a large tree is
	 * never held in memory as a whole; the texts are what a reporter sees in `reportFile()`.
	 * @param list<string> $files  as `findFiles()` returned them
	 * @param ?\Closure(int, array<string, float>, ?int): void $onProgress  files done, the paths in progress and, when
	 *   nothing is heard of the one in progress until it is done, its size
	 */
	public function run(
		array $files,
		bool $fix,
		Reporter $reporter,
		?WorkerPool $workers = null,
		?\Closure $onProgress = null,
		?int $maxWarnings = null,
	): RunResult
	{
		$reporter->start(new RunInfo($this->root, $fix, count($files), $this->typeAnalysis, $this->namespacesListed));
		/** @var array<string, FileResult|string> $ready the finished files, a cached one by the hash of its content */
		$ready = [];
		$order = $pending = $sizes = [];
		foreach ($files as $path) {
			$code = $this->read($path);
			if ($code !== null && $this->skipWhen && ($this->skipWhen)($code, $path)) {
				continue;
			}

			$order[] = $path;
			$hash = $code === null ? null : ResultCache::hashContent($path, $code);
			if ($hash !== null && $this->cache?->findClean($hash) !== null) {
				$ready[$path] = $hash;
			} else {
				$pending[] = $path;
				$sizes[$path] = strlen($code ?? '');
			}
		}

		foreach ($pending as $path) { // a configuration the rules do not fit fails before any file, and never in a worker
			$this->processors->get($path);
		}

		$ordered = $requeued = [];
		$scope = []; // the files the run can say something about to the baseline, and by which decisions
		$report = function () use (&$ready, &$ordered, &$scope, &$requeued, &$sizes, $order, $reporter): void {
			for ($next = count($ordered); isset($order[$next], $ready[$order[$next]]); $next++) {
				$path = $order[$next];
				$result = $ready[$path];
				unset($ready[$path]);
				if (is_string($result)) { // served by the cache, its text read only now and processed like the others where it changed since
					$code = $this->read($path);
					$baselined = $code !== null && ResultCache::hashContent($path, $code) === $result
						? $this->cache?->findClean($result)
						: null;
					if ($code === null) {
						$result = self::createUnreadable($path);
					} elseif ($baselined === null) {
						$this->processors->get($path);
						$requeued[] = $path;
						$sizes[$path] = strlen($code);
						return;
					} else {
						$this->baseline?->markMatched($path, $baselined);
						$result = new FileResult($path, $code, $code, baselined: $baselined, cached: true);
					}
				}

				if ($this->cache !== null && !$result->cached) {
					$this->remember($this->cache, $result);
				}

				if ($this->baseline !== null && $result->syntaxError === null && $result->failure === null) {
					$scope[$path] = $this->narrowed ? $this->processors->get($path)->getReportedDecisions() : null;
				}

				$reporter->reportFile($result);
				$ordered[] = FileSummary::of($result);
			}
		};

		$report();
		while (($queue = [...$pending, ...$requeued]) !== []) {
			$pending = $requeued = [];
			foreach ($this->processPending($queue, $sizes, $fix, $workers, $onProgress, count($order) - count($queue)) as $path => $result) {
				$ready[$path] = $result;
				$report();
			}
		}

		if (count($ordered) < count($order)) {
			throw new \RuntimeException('The workers returned no result for `' . $order[count($ordered)] . '`.');
		}

		if ($onProgress !== null) {
			$onProgress(count($files), [], null); // the whole scope is done, whatever was skipped along the way
		}

		$this->cache?->save();
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
	 * The results of the files the cache did not serve, in the order they are done: from the workers when there are
	 * any and more than one file, or a single one with the progress watched, since only the workers let it tick while
	 * a file is processed; else one by one, each file read when its turn comes.
	 * @param  list<string>  $paths
	 * @param  array<string, int>  $sizes  path => the length of its content
	 * @param  ?\Closure(int, array<string, float>, ?int): void  $onProgress
	 * @param  int  $done  files done before, the cached ones
	 * @return \Generator<string, FileResult>
	 */
	private function processPending(
		array $paths,
		array $sizes,
		bool $fix,
		?WorkerPool $workers,
		?\Closure $onProgress,
		int $done,
	): \Generator
	{
		if ($workers !== null && (count($paths) > 1 || ($paths && $onProgress !== null))) {
			$progress = $onProgress === null
				? null
				: fn(int $processed, array $running) => $onProgress($done + $processed, $running, null);
			foreach ($workers->process($paths, fn(string $path) => $this->read($path) ?? '', $progress) as $path => $result) {
				$this->baseline?->markMatched($result->path, $result->baselined);
				yield $path => $result;
			}

			return;
		}

		$collector = new CycleCollector;
		try {
			foreach ($paths as $path) {
				if ($onProgress !== null) {
					$onProgress($done++, [$path => microtime(as_float: true)], $sizes[$path]);
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
	 * A clean result makes its content known to the cache, with what the baseline matched in it; a fixed file
	 * without remaining violations makes the written content known too, unless there is a baseline, whose
	 * entries no run has yet held against the fixed lines.
	 */
	private function remember(ResultCache $cache, FileResult $result): void
	{
		if ($result->syntaxError !== null || $result->failure !== null || $result->warnings) {
			return;
		}

		if (!$result->violations && !$result->changed) {
			$cache->markClean(ResultCache::hashContent($result->path, $result->code), $result->baselined);
		} elseif (
			$result->written
			&& $result->output !== null
			&& !$result->remaining
			&& $this->baseline === null
		) {
			$cache->markClean(ResultCache::hashContent($result->path, $result->output));
		}
	}


	/**
	 * Processes a text that stands for the file at the path, with the rules that apply to it; nothing is written.
	 * @param  array<string, true>  $acceptedRisks  fingerprints of the occurrences whose risky fix is allowed on top of what the configuration allows
	 * @throws RuleException|ConvergenceException
	 */
	public function processCode(string $path, string $code, array $acceptedRisks = []): FileResult
	{
		$path = $this->relativize($path);
		$result = $this->processors->get($path)->process($path, $code, $acceptedRisks);
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
