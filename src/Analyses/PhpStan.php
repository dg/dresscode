<?php declare(strict_types=1);

namespace DressCode\Analyses;

use DressCode\Helpers;
use Nette\Utils\FileSystem;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
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
			$configs = [];
			foreach (self::ConfigFiles as $file) {
				if (is_file("$this->root/$file")) {
					$configs[] = "$this->root/$file";
					break;
				}
			}

			try {
				$this->container = new ContainerFactory($this->root)->create($this->tempDir, $configs, $this->analysedPaths, [$this->root]);
			} catch (\Throwable $e) {
				throw new \RuntimeException('PHPStan could not be started' . ($configs ? " with $configs[0]" : '') . ": {$e->getMessage()}", previous: $e);
			}
		}

		return $this->container;
	}
}
