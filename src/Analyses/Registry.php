<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols};
use PhpSyntax\Nodes\FileNode;


/**
 * Creates analyses on demand and keeps them per file until the file mutates, or until the pass ends for
 * a PassAnalysis. An analysis is any class that can be built from the file (and its path), or from nothing;
 * a rule asks for one through RuleContext::getAnalysis(). What the namespaces of the project declare outside
 * the file is one of them, and the resolver of names is built with it.
 * @internal
 */
final class Registry
{
	/** @var array<class-string, \Closure(FileNode, string): object> */
	private array $factories = [];

	/** @var \WeakMap<FileNode, array{int, array<class-string, object>}>  revision and analyses of the file */
	private \WeakMap $cache;

	/** @var \WeakMap<FileNode, array<class-string, object>>  the analyses of the pass, kept over its mutations */
	private \WeakMap $passCache;


	public function __construct(NamespacedSymbols $namespacedSymbols = new NamespacedSymbols)
	{
		$this->cache = new \WeakMap;
		$this->passCache = new \WeakMap;
		$this->factories[NamespacedSymbols::class] = fn() => $namespacedSymbols;
		$this->factories[NameResolver::class] = fn(FileNode $file) => new NameResolver($file, $namespacedSymbols);
	}


	/**
	 * Registers an analysis; without a factory it is created as new $class($file), or new $class when
	 * its constructor takes no parameter.
	 * @param  class-string  $class
	 * @param  ?\Closure(FileNode, string): object  $factory  given the file and its path
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
	public function get(FileNode $file, string $class, string $path = ''): object
	{
		if (!isset($this->factories[$class])) {
			$this->register($class);
		}

		if (is_subclass_of($class, PassAnalysis::class)) {
			$analyses = $this->passCache[$file] ?? [];
			$analysis = $analyses[$class] ??= $this->factories[$class]($file, $path);
			$this->passCache[$file] = $analyses;

		} else {
			[$revision, $analyses] = $this->cache[$file] ?? [null, []];
			if ($revision !== $file->revision) {
				$analyses = [];
			}

			$analysis = $analyses[$class] ??= $this->factories[$class]($file, $path);
			$this->cache[$file] = [$file->revision, $analyses];
		}

		if (!$analysis instanceof $class) {
			throw new \LogicException("The factory of $class returned " . $analysis::class . '.');
		}

		return $analysis;
	}


	/**
	 * The analysis, or null for one nothing registered that cannot be built from the file alone.
	 * @template T of object
	 * @param  class-string<T>  $class
	 * @return ?T
	 */
	public function find(FileNode $file, string $class, string $path = ''): ?object
	{
		return isset($this->factories[$class]) || (new \ReflectionClass($class)->getConstructor()?->getNumberOfRequiredParameters() ?? 0) <= 1
			? $this->get($file, $class, $path)
			: null;
	}


	/** A pass over the file begins: what the previous one computed for the whole pass is dropped. */
	public function beginPass(FileNode $file): void
	{
		unset($this->passCache[$file]);
	}
}
