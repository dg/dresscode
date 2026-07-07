<?php declare(strict_types=1);

namespace DressCode;

use DressCode\Engine\FileProcessor;
use DressCode\Engine\FileResult;
use DressCode\Engine\PathGlob;
use DressCode\Engine\RunResult;
use Nette\Utils\Finder;
use function count, in_array;


/**
 * Runs the file processor over the files of a project. Paths are relative to the root, with slashes.
 */
final class Engine
{
	private readonly string $root;


	/**
	 * @param list<string> $skip  patterns of paths left out
	 * @param array<string, list<string>> $ruleSkip  rule name → patterns of paths the rule is not applied to
	 * @param list<string> $extensions
	 * @param ?\Closure(string $content, string $path): bool $skipWhen  files left out by their content
	 */
	public function __construct(
		private readonly FileProcessor $processor,
		string $root,
		private readonly array $skip = [],
		private readonly array $ruleSkip = [],
		private readonly array $extensions = ['php'],
		private readonly ?\Closure $skipWhen = null,
	) {
		$this->root = rtrim(str_replace('\\', '/', $root), '/');
	}


	/**
	 * @param list<string> $paths  files and directories, absolute or relative to the root
	 * @throws RuleException|ConvergenceException
	 */
	public function run(array $paths, bool $fix, Reporter $reporter): RunResult
	{
		$files = $this->findFiles($paths);
		$reporter->start(count($files), $fix);
		$results = [];
		foreach ($files as $path) {
			$absolute = $this->root . '/' . $path;
			$code = @file_get_contents($absolute); // @ - reported as exception
			if ($code === false) {
				throw new \RuntimeException("Cannot read file $path.");
			}

			if ($this->skipWhen && ($this->skipWhen)($code, $path)) {
				continue;
			}

			$result = $this->processFile($path, $code);
			if ($fix && $result->isChanged()) {
				if (@file_put_contents($absolute, $result->output) === false) { // @ - reported as exception
					throw new \RuntimeException("Cannot write file $path.");
				}

				$result->written = true;
			}

			$reporter->reportFile($result);
			$results[] = $result;
		}

		$result = new RunResult($results, $fix);
		$reporter->finish($result);
		return $result;
	}


	/**
	 * Processes a text that stands for the file at the path, with the rules that apply to it; nothing is written.
	 * @throws RuleException|ConvergenceException
	 */
	public function processFile(string $path, string $code): FileResult
	{
		$path = $this->relativize($path);
		$rules = null;
		if ($this->ruleSkip) {
			$rules = [];
			foreach ($this->processor->getRules() as $rule) {
				$patterns = $this->ruleSkip[RuleInfo::of($rule)->name] ?? [];
				if (!self::matches($patterns, $path)) {
					$rules[] = $rule;
				}
			}
		}

		return $this->processor->process($path, $code, $rules);
	}


	/**
	 * Files under the paths with one of the extensions, minus the skipped ones; an explicitly given file
	 * is taken as is. Sorted, relative to the root.
	 * @param  list<string>  $paths
	 * @return list<string>
	 */
	public function findFiles(array $paths): array
	{
		$files = [];
		foreach ($paths as $path) {
			$path = $this->relativize($path);
			$absolute = $path === '' ? $this->root : $this->root . '/' . $path;
			if (is_file($absolute)) {
				$files[$path] = true;
			} elseif (is_dir($absolute)) {
				$finder = Finder::findFiles(array_map(fn($ext) => "*.$ext", $this->extensions))
					->from($absolute)
					->descentFilter(fn(\SplFileInfo $dir) => !self::matches($this->skip, $this->relativize($dir->getPathname())));
				foreach ($finder as $file) {
					$relative = $this->relativize($file->getPathname());
					if (!self::matches($this->skip, $relative)) {
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
	 * Path with slashes, relative to the root when it lies under it.
	 */
	public function relativize(string $path): string
	{
		$path = rtrim(str_replace('\\', '/', $path), '/');
		if ($path === $this->root) {
			return '';
		} elseif (str_starts_with($path, $this->root . '/')) {
			$path = substr($path, strlen($this->root) + 1);
		}

		return implode('/', array_filter(explode('/', $path), fn(string $segment) => $segment !== '.')); // "./a" and "." would match the ".*" skip
	}


	/** @param list<string> $patterns */
	private static function matches(array $patterns, string $path): bool
	{
		foreach ($patterns as $pattern) {
			if (PathGlob::match($pattern, $path)) {
				return true;
			}
		}

		return false;
	}


	public function hasExtension(string $path): bool
	{
		return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $this->extensions, strict: true);
	}
}
