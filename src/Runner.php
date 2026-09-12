<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Config\FileProcessors;
use DressCode\Engine\Baseline;
use DressCode\Engine\FileProcessor;
use DressCode\Engine\ResultCache;
use DressCode\Engine\WorkerPool;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use function count, in_array, sprintf, strlen;


/**
 * Runs the file processor over the files of a project. Paths are relative to the root, with slashes.
 */
final class Runner
{
	private readonly string $root;
	private readonly FileProcessors $processors;


	public function __construct(
		FileProcessor|FileProcessors $processors,
		string $root,
		/** @var list<string> patterns of paths left out */
		private readonly array $excludePaths = [],
		/** @var array<string, list<string>> rule name → patterns of paths the rule is not applied to */
		private readonly array $ruleExcludePaths = [],
		/** @var list<string> */
		private readonly array $fileExtensions = ['php'],
		/** @var ?\Closure(string $content, string $path): bool files left out by their content */
		private readonly ?\Closure $skipWhen = null,
		/** violations left unreported */
		private readonly ?Baseline $baseline = null,
		/** contents known to be clean, skipped without processing */
		private readonly ?ResultCache $cache = null,
	) {
		$this->root = Helpers::canonicalizePath($root);
		$this->processors = $processors instanceof FileProcessor ? FileProcessors::of($processors) : $processors;
	}


	/**
	 * Processes the files; a file whose rules fail is reported as a failure and the run goes on.
	 * @param list<string> $files  as findFiles() returned them
	 * @param ?\Closure(int, array<string, float>): void $onProgress  files done and the paths in progress
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
		$reporter->start(count($files), $fix);
		$results = [];
		$pending = [];
		foreach ($files as $path) {
			$code = $this->read($path);
			if ($this->skipWhen && ($this->skipWhen)($code, $path)) {
				continue;
			}

			if ($this->cache?->isClean(ResultCache::hashContent($code, $this->processors->getKey($path)))) {
				$results[$path] = new FileResult($path, $code, $code);
				$results[$path]->cached = true;
			} else {
				$results[$path] = null;
				$pending[$path] = $code;
			}
		}

		$done = count($results) - count($pending); // the cached ones are already done
		if ($workers !== null && count($pending) > 1) {
			$report = $onProgress === null
				? null
				: fn(int $processed, array $running) => $onProgress($done + $processed, $running);
			foreach ($workers->process($pending, $report) as $path => $result) {
				$this->baseline?->markUsed($result->path, $result->baselined);
				$results[$path] = $result;
			}
		} else {
			foreach ($pending as $path => $code) {
				if ($onProgress !== null) {
					$onProgress($done, [$path => microtime(as_float: true)]);
				}

				$results[$path] = $this->processPath($path, $fix, $code);
				$done++;
			}
		}

		if ($onProgress !== null) {
			$onProgress(count($files), []); // the whole scope is done, whatever was skipped along the way
		}

		$ordered = [];
		foreach ($results as $path => $result) {
			if ($result === null) {
				throw new \RuntimeException("The workers returned no result for $path.");
			}

			if ($this->cache !== null && !$result->cached) {
				$this->remember($result, $this->processors->getKey($result->path));
			}

			$reporter->reportFile($result);
			$ordered[] = $result;
		}

		$results = $ordered;
		$this->cache?->save();
		$unused = $this->baseline?->countUnused() ?? 0;
		$result = new RunResult(
			$results,
			$fix,
			baselined: $this->baseline?->countMatched() ?? 0,
			warnings: $unused ? [sprintf('%d %s of the baseline no longer match a violation; regenerate it', $unused, $unused === 1 ? 'entry' : 'entries')] : [],
			maxWarnings: $maxWarnings,
		);
		$reporter->finish($result);
		return $result;
	}


	/**
	 * Processes one file of the project: a failing rule is a failed result, fix writes a changed file back.
	 * @param  ?string  $code  the content, read from the file when null
	 * @throws \RuntimeException  when the file cannot be read or written
	 */
	public function processPath(string $path, bool $fix, ?string $code = null): FileResult
	{
		$path = $this->relativize($path);
		$code ??= $this->read($path);
		try {
			$result = $this->processFile($path, $code);
		} catch (RuleException|ConvergenceException $e) {
			$detail = $e instanceof ConvergenceException && $e->diff !== '' ? "\n$e->diff" : '';
			$result = new FileResult($path, $code, output: null, failure: $e->getMessage() . $detail);
		}

		if ($fix && $result->isChanged()) {
			if (@file_put_contents($this->toAbsolute($path), $result->output) === false) { // @ - reported as exception
				throw new \RuntimeException("Cannot write file $path.");
			}

			$result->written = true;
		}

		return $result;
	}


	/** @throws \RuntimeException */
	private function read(string $path): string
	{
		$code = @file_get_contents($this->toAbsolute($path)); // @ - reported as exception
		if ($code === false) {
			throw new \RuntimeException("Cannot read file $path.");
		}

		return $code;
	}


	/**
	 * A clean result makes its content known to the cache; a fixed file without remaining violations makes
	 * the written content known too.
	 */
	private function remember(FileResult $result, string $variant): void
	{
		if ($result->error !== null || $result->failure !== null || $result->warnings) {
			return;
		}

		if (!$result->violations && !$result->isChanged()) {
			$this->cache?->markClean(ResultCache::hashContent($result->code, $variant));
		} elseif ($result->written && $result->output !== null && !$result->getUnfixedViolations()) {
			$this->cache?->markClean(ResultCache::hashContent($result->output, $variant));
		}
	}


	/**
	 * Processes a text that stands for the file at the path, with the rules that apply to it; nothing is written.
	 * @throws RuleException|ConvergenceException
	 */
	public function processFile(string $path, string $code): FileResult
	{
		$path = $this->relativize($path);
		$processor = $this->processors->get($path);
		$rules = null;
		if ($this->ruleExcludePaths) {
			$rules = [];
			foreach ($processor->getRules() as $rule) {
				$patterns = $this->ruleExcludePaths[RuleInfo::of($rule)->name] ?? [];
				if (!Helpers::matchesAny($patterns, $path)) {
					$rules[] = $rule;
				}
			}
		}

		$result = $processor->process($path, $code, $rules);
		$this->baseline?->markUsed($result->path, $result->baselined);
		return $result;
	}


	/**
	 * Indexes of the `for` blocks that apply to the file.
	 * @return list<int>
	 */
	public function findBlocksFor(string $path): array
	{
		return $this->processors->findBlocks($this->relativize($path));
	}


	/**
	 * Rules the configuration keeps away from the file, by name, with the reason a reader wants.
	 * @return array<string, string>
	 */
	public function getExcludedRules(string $path): array
	{
		$path = $this->relativize($path);
		$excluded = [];
		foreach ($this->ruleExcludePaths as $rule => $patterns) {
			if (Helpers::matchesAny($patterns, $path)) {
				$excluded[$rule] = 'the configuration keeps it away from this path';
			}
		}

		return $excluded;
	}


	/**
	 * Files under the paths with one of the extensions, minus the excluded ones; an explicitly given file
	 * is taken as is, unless $skipExcluded lets the excluded paths leave it out like a found one, which
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
				throw new \RuntimeException("Path $path does not exist.");
			}
		}

		$files = array_keys($files);
		sort($files, SORT_STRING);
		return $files;
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


	public function getProcessor(): FileProcessor
	{
		return $this->processors->getBase();
	}


	public function hasExtension(string $path): bool
	{
		return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $this->fileExtensions, strict: true);
	}
}
