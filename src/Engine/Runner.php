<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{ConvergenceException, FileResult, Reporter, RuleException};
use function count, is_string, sprintf, strlen;


/**
 * Runs the file processor over the files of a project. Paths are relative to the root, with slashes.
 * @internal
 */
final readonly class Runner
{
	/** the files of the project, which the run finds and writes */
	public FileFinder $files;
	private FileProcessors $processors;


	/**
	 * @param  list<string>  $excludePaths  patterns of paths left out
	 * @param  list<string>  $fileExtensions
	 */
	public function __construct(
		FileProcessor|FileProcessors $processors,
		string $root,
		array $excludePaths = [],
		array $fileExtensions = ['php'],
		/** @var ?\Closure(string $content, string $path): bool files left out by their content */
		private ?\Closure $skipWhen = null,
		/** violations left unreported */
		private ?Baseline $baseline = null,
		/** contents known to be clean, skipped without processing */
		private ?ResultCache $cache = null,
		/** the run is narrowed to some of the decisions, so it says nothing about the baseline entries of the others */
		private bool $narrowed = false,
		/** measures the cycle collection of the files processed in this process */
		private ?Profiler $profiler = null,
		/** @var ?\Closure(): void  fills the cache the workers share, which those started together would all write at once */
		public ?\Closure $warmUp = null,
		/** @var ?\Closure(): ?list<string>  the files the results depend on beyond the configuration, once the run knows them */
		public ?\Closure $findDependencies = null,
		/** @var ?\Closure(): list<string>  what the analyses have to tell the user about the run, once they know it */
		public ?\Closure $findWarnings = null,
		private TypeAnalysisStatus $typeAnalysis = TypeAnalysisStatus::Unavailable,
		/** a rule the configuration turns on does not run without the types */
		private bool $typesMissing = false,
		/** the configuration lists functions or constants the namespaces declare */
		private bool $namespacesListed = false,
	) {
		$this->files = new FileFinder($root, $excludePaths, $fileExtensions);
		$this->processors = $processors instanceof FileProcessor ? FileProcessors::of($processors) : $processors;
	}


	/** What a reporter is told of the run before the first file. */
	public function createRunInfo(bool $fix, int $fileCount): RunInfo
	{
		return new RunInfo($this->files->root, $fix, $fileCount, $this->typeAnalysis, $this->typesMissing, $this->namespacesListed);
	}


	/**
	 * Processes the files; a file whose rules fail is reported as a failure and the run goes on. A file is reported
	 * as soon as the files before it are, and the run keeps its result without the texts, so that a large tree is
	 * never held in memory as a whole; the texts are what a reporter sees in `reportFile()`.
	 * @param list<string> $files  as `findFiles()` returned them
	 * @param ?\Closure(int, array<string, float>, ?int): void $onProgress  files done, the paths in progress and, when
	 *   nothing is heard of the one in progress until it is done, its size
	 * @param ?LineRanges $lines  the lines of the files the run reports on
	 */
	public function run(
		array $files,
		bool $fix,
		Reporter $reporter,
		?WorkerPool $workers = null,
		?\Closure $onProgress = null,
		?int $maxWarnings = null,
		?LineRanges $lines = null,
	): RunResult
	{
		$reporter->start($this->createRunInfo($fix, count($files)));
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
		$report = function () use (&$ready, &$ordered, &$scope, &$requeued, &$sizes, $order, $reporter, $lines, $fix): void {
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

				$result = $lines?->select($result, $fix) ?? $result;
				$reporter->reportFile($result);
				$ordered[] = FileSummary::of($result);
			}
		};

		$report();
		while (($queue = [...$pending, ...$requeued]) !== []) {
			$pending = $requeued = [];
			foreach ($this->processPending(
				$queue,
				$sizes,
				$fix,
				$workers,
				$onProgress,
				count($order) - count($queue),
			) as $path => $result) {
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

		$dependencies = [...($this->findDependencies)?->__invoke() ?? [], ...$workers?->getDependencies() ?? []];
		if ($dependencies !== []) {
			$this->cache?->recordDependencies(array_values(array_unique($dependencies)));
		}

		$this->cache?->save();
		$unmatched = $this->baseline?->countUnmatched($scope) ?? 0;
		$result = new RunResult(
			$ordered,
			$fix,
			baselined: $this->baseline?->countMatched() ?? 0,
			warnings: array_values(array_unique([
				...($this->findWarnings)?->__invoke() ?? [],
				...$workers?->getWarnings() ?? [],
				...($unmatched
					? [sprintf(
						'%d %s of the baseline no longer %s a violation; regenerate it with `dresscode baseline`.',
						$unmatched,
						$unmatched === 1 ? 'entry' : 'entries',
						$unmatched === 1 ? 'matches' : 'match',
					)]
					: []),
			])),
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

		$collector = new CycleCollector($this->profiler);
		try {
			foreach ($paths as $path) {
				if ($onProgress !== null) {
					$onProgress($done++, [$path => microtime(as_float: true)], $sizes[$path]);
				}

				$result = $this->processPath($path, $fix);
				$collector->collect();
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
		$path = $this->files->relativize($path);
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
				return new FileResult(
					$path,
					$code,
					output: null,
					failure: 'The file changed while it was being fixed, so it was not written; run `fix` again.',
				);
			}

			if (!Helpers::writeFile($this->files->toAbsolute($path), (string) $result->output)) {
				return new FileResult($path, $code, output: null, failure: "Cannot write file `$path`.");
			}

			$result->markWritten();
		}

		return $result;
	}


	/** The content of the file, null when it cannot be read. */
	private function read(string $path): ?string
	{
		$code = @file_get_contents($this->files->toAbsolute($path)); // @ the failure is the result of the file
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
		$key = ResultCache::hashContent($result->path, $result->code);
		if ($result->syntaxError !== null || $result->failure !== null || $result->warnings) {
			$cache->forget($key);
			return;
		}

		if (!$result->violations && !$result->changed) {
			$cache->markClean($key, $result->baselined);
			return;
		}

		$cache->forget($key);
		if (
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
	 * @param  array<string, true>  $acceptedRisks  fingerprints of the occurrences whose risky fix is allowed on top of
	 *   what the configuration allows
	 * @throws RuleException|ConvergenceException
	 */
	public function processCode(string $path, string $code, array $acceptedRisks = []): FileResult
	{
		$path = $this->files->relativize($path);
		$result = $this->processors->get($path)->process($path, $code, $acceptedRisks);
		$this->baseline?->markMatched($result->path, $result->baselined);
		return $result;
	}


	/** Whether the cache knows the text standing for the file to be clean, so that processing it would report nothing. */
	public function isKnownClean(string $path, string $code): bool
	{
		return $this->cache?->findClean(ResultCache::hashContent($this->files->relativize($path), $code)) !== null;
	}


	/**
	 * Indexes of the overrides that apply to the file.
	 * @return list<int>
	 */
	public function findOverridesFor(string $path): array
	{
		return $this->processors->findOverrides($this->files->relativize($path));
	}
}
