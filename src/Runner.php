<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Config\FileProcessors;
use DressCode\Engine\Baseline;
use DressCode\Engine\FileProcessor;
use DressCode\Engine\ResultCache;
use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use function count, sprintf, strlen;


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
		/** @var list<string> */
		private readonly array $fileExtensions = ['php'],
		/** @var ?\Closure(string $content, string $path): bool files left out by their content */
		private readonly ?\Closure $skipWhen = null,
		/** violations left unreported */
		private readonly ?Baseline $baseline = null,
		/** contents known to be clean, skipped without processing */
		private readonly ?ResultCache $cache = null,
		/** the run is narrowed to some of the rules, so it says nothing about the baseline entries of the others */
		private readonly bool $narrowed = false,
	) {
		$this->root = Helpers::canonicalizePath($root);
		$this->processors = $processors instanceof FileProcessor ? FileProcessors::of($processors) : $processors;
	}


	/**
	 * Processes the files; a file whose rules fail is reported as a failure and the run goes on. A file is reported
	 * as soon as the files before it are, and the run keeps its result without the texts, so that a large tree is
	 * never held in memory as a whole; the texts are what a reporter sees in reportFile().
	 * @param list<string> $files  as findFiles() returned them
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
		$reporter->start(count($files), $fix);
		$order = $ready = $pending = [];
		foreach ($files as $path) {
			$code = $this->read($path);
			if ($this->skipWhen && ($this->skipWhen)($code, $path)) {
				continue;
			}

			$order[] = $path;
			$baselined = $this->cache?->findClean(ResultCache::hashContent($path, $code));
			if ($baselined !== null) {
				$result = new FileResult($path, $code, $code, baselined: $baselined);
				$result->cached = true;
				$ready[$path] = $result->withoutTexts();
				$this->baseline?->markUsed($path, $baselined);
			} else {
				$pending[] = $path;
			}
		}

		$ordered = [];
		$scope = []; // the files the run can say something about to the baseline, and by which rules
		$report = function () use (&$ready, &$ordered, &$scope, $order, $reporter): void {
			for ($next = count($ordered); isset($order[$next], $ready[$order[$next]]); $next++) {
				$path = $order[$next];
				$result = $ready[$path];
				unset($ready[$path]);
				if ($this->cache !== null && !$result->cached) {
					$this->remember($result);
				}

				if ($this->baseline !== null && $result->error === null && $result->failure === null) {
					$scope[$path] = $this->narrowed
						? array_map(fn(Rule $rule) => RuleInfo::of($rule)->name, $this->processors->get($path)->getRules())
						: null;
				}

				$reporter->reportFile($result);
				$ordered[] = $result->withoutTexts();
			}
		};

		$report();
		foreach ($this->processPending($pending, $fix, $onProgress, count($order) - count($pending)) as $path => $result) {
			$ready[$path] = $result;
			$report();
		}

		if ($onProgress !== null) {
			$onProgress(count($files), []); // the whole scope is done, whatever was skipped along the way
		}

		$this->cache?->save();
		$unused = $this->baseline?->countUnused($scope) ?? 0;
		$result = new RunResult(
			$ordered,
			$fix,
			baselined: $this->baseline?->countMatched() ?? 0,
			warnings: $unused ? [sprintf(
				'%d %s of the baseline no longer %s a violation; regenerate it',
				$unused,
				$unused === 1 ? 'entry' : 'entries',
				$unused === 1 ? 'matches' : 'match',
			)] : [],
			maxWarnings: $maxWarnings,
		);
		$reporter->finish($result);
		return $result;
	}


	/**
	 * The results of the files the cache did not serve, in the order they are done, each file read when its turn
	 * comes.
	 * @param  list<string>  $paths
	 * @param  ?\Closure(int, array<string, float>): void  $onProgress
	 * @param  int  $done  files done before, the cached ones
	 * @return \Generator<string, FileResult>
	 */
	private function processPending(
		array $paths,
		bool $fix,
		?\Closure $onProgress,
		int $done,
	): \Generator
	{
		foreach ($paths as $path) {
			if ($onProgress !== null) {
				$onProgress($done++, [$path => microtime(as_float: true)]);
			}

			yield $path => $this->processPath($path, $fix);
		}
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
			if ($this->read($path) !== $code) { // saved meanwhile by someone else, whose text wins
				return new FileResult($path, $code, output: null, failure: 'The file changed while it was being fixed, so it was not written; run the fixer again.');
			}

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
	 * A clean result makes its content known to the cache, with what the baseline silenced in it; a fixed file
	 * without remaining violations makes the written content known too, unless there is a baseline, whose
	 * entries no run has yet held against the fixed lines.
	 */
	private function remember(FileResult $result): void
	{
		if ($result->error !== null || $result->failure !== null || $result->warnings) {
			return;
		}

		if (!$result->violations && !$result->isChanged()) {
			$this->cache?->markClean(ResultCache::hashContent($result->path, $result->code), $result->baselined);
		} elseif (
			$result->written
			&& $result->output !== null
			&& !$result->remaining
			&& $this->baseline === null
		) {
			$this->cache?->markClean(ResultCache::hashContent($result->path, $result->output));
		}
	}


	/**
	 * Processes a text that stands for the file at the path, with the rules that apply to it; nothing is written.
	 * @throws RuleException|ConvergenceException
	 */
	public function processFile(string $path, string $code): FileResult
	{
		$path = $this->relativize($path);
		$result = $this->processors->get($path)->process($path, $code);
		$this->baseline?->markUsed($result->path, $result->baselined);
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
}
