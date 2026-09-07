<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\Helpers;
use Nette\Utils\FileSystem;
use PHPStan\Analyser\{NodeScopeResolver, Scope, ScopeContext, ScopeFactory};
use PHPStan\DependencyInjection\{Container, ContainerFactory};
use PHPStan\ExtensionInstaller\GeneratedConfig;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ReflectionProvider;


/**
 * PHPStan of the project in the process, the one `phpstan/phpstan` in its vendor: a container built once from
 * the configuration of the project, the parser and the scope resolver the types come from. Only what PHPStan
 * marks @api is used, so that a minor version of it changes nothing here.
 * @internal
 */
final class PhpStan
{
	private const ConfigFiles = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

	private ?Container $container = null;


	public function __construct(
		private readonly string $root,
		/** @var list<string> where the classes of the project are declared, besides its Composer autoload */
		private readonly array $analysedPaths,
		private readonly string $tempDir,
	) {
	}


	/** Whether the project has PHPStan: the analysis is available only then. */
	public static function isAvailable(): bool
	{
		return class_exists(ContainerFactory::class);
	}


	/** @return array<\PhpParser\Node\Stmt> */
	public function parse(string $code): array
	{
		// defaultAnalysisParser routes a string to the simple parser, which drops the bodies of functions
		$parser = $this->getContainer()->getService('currentPhpVersionRichParser');
		assert($parser instanceof Parser);
		return $parser->parseString($code);
	}


	/**
	 * Computes the scope of every node of the file and hands each to the callback. The path is the one of the run,
	 * relative to the root; PHPStan reads the declarations of a file from the disk and resolves a relative path
	 * against the working directory, which is not the root of the run, so it is given an absolute one.
	 * @param  array<\PhpParser\Node\Stmt>  $ast
	 * @param  callable(\PhpParser\Node, Scope): void  $callback
	 */
	public function resolveScopes(string $path, array $ast, callable $callback): void
	{
		$path = FileSystem::isAbsolute($path) ? $path : Helpers::canonicalizePath($this->root) . '/' . $path;
		$container = $this->getContainer();
		$resolver = $container->getByType(NodeScopeResolver::class);
		$resolver->setAnalysedFiles([$path]);
		$scope = $container->getByType(ScopeFactory::class)->create(ScopeContext::create($path));
		$resolver->processNodes($ast, $scope, $callback);
	}


	/**
	 * The declared spelling of a class, interface, trait or enum the project, its packages or PHP declare, given its
	 * fully qualified name in any letter case without a leading backslash; null for a name nothing declares.
	 */
	public function findClassName(string $name): ?string
	{
		$provider = $this->getContainer()->getByType(ReflectionProvider::class);
		return $provider->hasClass($name) ? $provider->getClass($name)->getName() : null;
	}


	private function getContainer(): Container
	{
		if ($this->container === null) {
			$config = null;
			foreach (self::ConfigFiles as $file) {
				if (is_file("$this->root/$file")) {
					$config = "$this->root/$file";
					break;
				}
			}

			try {
				$container = new ContainerFactory($this->root)->create(
					$this->tempDir,
					[...self::findExtensionConfigs(), ...($config === null ? [] : [$config])],
					$this->analysedPaths,
					[$this->root],
				);
				// the container does not run them, the command of PHPStan does; an extension such as Larastan needs them
				foreach ($container->getParameter('bootstrapFiles') as $file) {
					(static function (string $file): void { require_once $file; })($file);
				}
			} catch (\Throwable $e) {
				throw new \RuntimeException('PHPStan could not be started' . ($config ? ' with ' . Helpers::formatCode($config) : '') . ": {$e->getMessage()}", previous: $e);
			}

			$this->container = $container;
		}

		return $this->container;
	}


	/**
	 * The configurations of the extensions phpstan/extension-installer registered, which the command of PHPStan
	 * includes before the one of the project.
	 * @return list<string>
	 */
	private static function findExtensionConfigs(): array
	{
		if (!class_exists(GeneratedConfig::class)) {
			return [];
		}

		$generated = new \ReflectionClass(GeneratedConfig::class);
		$dir = dirname((string) $generated->getFileName());
		/** @var array<array{relative_install_path: string, extra: array{includes?: list<string>}}> $extensions  generated for the project */
		$extensions = $generated->getConstant('EXTENSIONS');
		$configs = [];
		foreach ($extensions as $extension) {
			foreach ($extension['extra']['includes'] ?? [] as $include) {
				$configs[] = "$dir/$extension[relative_install_path]/$include";
			}
		}

		return $configs;
	}
}
