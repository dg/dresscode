<?php declare(strict_types=1);

namespace DressCode\Analyses;

use PhpSyntax\Nodes\FileNode;


/**
 * Creates analyses on demand and keeps them per file until the file mutates. An analysis is any class that can
 * be built from the file, or from nothing; a rule asks for one through RuleContext::getAnalysis().
 * @internal
 */
final class Registry
{
	/** @var array<class-string, \Closure(FileNode): object> */
	private array $factories = [];

	/** @var \WeakMap<FileNode, array{int, array<class-string, object>}>  revision and analyses of the file */
	private \WeakMap $cache;


	public function __construct()
	{
		$this->cache = new \WeakMap;
	}


	/**
	 * Registers an analysis; without a factory it is created as new $class($file), or new $class when
	 * its constructor takes no parameter.
	 * @param  class-string  $class
	 * @param  ?\Closure(FileNode): object  $factory
	 */
	public function register(string $class, ?\Closure $factory = null): void
	{
		$takesFile = new \ReflectionClass($class)->getConstructor()?->getNumberOfParameters() > 0;
		$this->factories[$class] = $factory ?? ($takesFile ? fn(FileNode $file) => new $class($file) : fn() => new $class);
	}


	/**
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @return T
	 */
	public function get(FileNode $file, string $class): object
	{
		[$revision, $analyses] = $this->cache[$file] ?? [null, []];
		if ($revision !== $file->revision) {
			$analyses = [];
		}

		if (!isset($this->factories[$class])) {
			$this->register($class);
		}

		$analysis = $analyses[$class] ??= $this->factories[$class]($file);
		$this->cache[$file] = [$file->revision, $analyses];
		if (!$analysis instanceof $class) {
			throw new \LogicException("The factory of $class returned " . $analysis::class . '.');
		}

		return $analysis;
	}
}
