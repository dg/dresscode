<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\Helpers;
use Nette\Utils\FileSystem;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\{Class_, ClassLike, Enum_, EnumCase, Interface_};
use PhpParser\NodeFinder;
use PHPStan\Analyser\{NodeScopeResolver, Scope, ScopeContext, ScopeFactory};
use PHPStan\BetterReflection\Reflection\Exception\CircularReference;
use PHPStan\DependencyInjection\{Container, ContainerFactory};
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\BetterReflection\BetterReflectionProvider;
use PHPStan\Reflection\{ClassReflection, ReflectionProvider};
use PHPStan\Type\Type;
use function is_string;


/**
 * PHPStan of the project in the process, the one `phpstan/phpstan` in its vendor: a container built once from
 * the configuration of the project, the parser and the scope resolver the types come from. Only what PHPStan
 * marks @api is used, so that a minor version of it changes nothing here, with two exceptions, both about the
 * reflection living in static state of PHPStan: the container created last takes it over, and handing it back to
 * another container is internal, as PHPStan does it itself where it switches between two; and its cache of anonymous
 * classes holds the container that reflected each, so it is emptied before a PHPStan of another text of a pass is made.
 * @internal
 */
final class PhpStan
{
	private const ConfigFiles = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

	private ?Container $container = null;

	/** @var ?array{string, string}  the path whose declarations are read from the other file, a text of a pass */
	private ?array $replacement = null;

	/** @var array<string, array{string, list<list<string>>}>  path → hash of its text on the disk and what it declares */
	private array $diskDeclarations = [];

	/** @var ?array{string, self}  hash of the text of a pass and the PHPStan reading it */
	private ?array $derived = null;


	public function __construct(
		private readonly string $root,
		/** @var list<string> where the classes of the project are declared, besides its Composer autoload */
		private readonly array $analysedPaths,
		private readonly string $tempDir,
	) {
	}


	public function __destruct()
	{
		if ($this->replacement !== null) {
			@unlink($this->replacement[1]); // @ - the file may be gone
		}
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
	 * The PHPStan for a text of the file other than the one on the disk, from which PHPStan reads the declarations
	 * of the file: this one where the text declares the same classes with the same parents, interfaces and traits,
	 * otherwise one reading the declarations of the file from the text, as the editor mode of PHPStan does. A class
	 * whose parent another file of the run renamed still has the hierarchy the disk gives it.
	 * @param  array<\PhpParser\Node\Stmt>  $ast  the text parsed
	 */
	public function deriveFor(string $path, string $code, array $ast): self
	{
		$path = $this->toAbsolutePath($path);
		$disk = @file_get_contents($path); // @ - the file may not exist, as the code of stdin does not
		if ($disk === $code) {
			return $this;
		}

		$diskHash = hash('xxh128', (string) $disk);
		if (($this->diskDeclarations[$path][0] ?? null) !== $diskHash) {
			$this->diskDeclarations[$path] = [$diskHash, $disk === false ? [] : self::collectDeclarations($this->parse($disk))];
		}

		if ($this->diskDeclarations[$path][1] === self::collectDeclarations($ast)) {
			return $this;
		}

		$hash = hash('xxh128', "$path|$code");
		if ($this->derived === null || $this->derived[0] !== $hash) {
			self::forgetAnonymousClasses();
			$file = Helpers::canonicalizePath($this->tempDir) . '/pass/' . getmypid() . "-$hash.php";
			FileSystem::write($file, $code);
			$derived = clone $this;
			$derived->container = null;
			$derived->replacement = [$path, $file];
			$derived->diskDeclarations = [];
			$derived->derived = null;
			$this->derived = [$hash, $derived];
		}

		return $this->derived[1];
	}


	/** Empties the cache of anonymous classes of PHPStan; should PHPStan rename or drop it, nothing is emptied. */
	private static function forgetAnonymousClasses(): void
	{
		if (class_exists(BetterReflectionProvider::class, false) && property_exists(BetterReflectionProvider::class, 'anonymousClasses')) {
			\Closure::bind(static function (): void { self::$anonymousClasses = []; }, null, BetterReflectionProvider::class)();
		}
	}


	/**
	 * Computes the scope of every node of the file and hands each to the callback. The path is the one of the run,
	 * relative to the root; PHPStan reads the declarations of a file from the disk and resolves a relative path
	 * against the working directory, which is not the root of the run, so it is given an absolute one. A class whose
	 * ancestors run in a circle stops PHPStan, and the nodes after it get no scope.
	 * @param  array<\PhpParser\Node\Stmt>  $ast
	 * @param  callable(\PhpParser\Node, Scope): void  $callback
	 */
	public function resolveScopes(string $path, array $ast, callable $callback): void
	{
		$path = $this->toAbsolutePath($path);
		$path = $this->replacement !== null && $path === $this->replacement[0] ? $this->replacement[1] : $path;
		$container = $this->getContainer();
		$resolver = $container->getByType(NodeScopeResolver::class);
		$resolver->setAnalysedFiles([$path]);
		$scope = $container->getByType(ScopeFactory::class)->create(ScopeContext::create($path));
		try {
			$resolver->processNodes($ast, $scope, $callback);
		} catch (CircularReference) {
		}
	}


	/**
	 * The declared spelling of a class, interface, trait or enum the project, its packages or PHP declare, given its
	 * fully qualified name in any letter case without a leading backslash; null for a name nothing declares or PHP
	 * refuses to load.
	 */
	public function findClassName(string $name): ?string
	{
		return $this->findClass($name)?->getName();
	}


	/**
	 * The class, interface, trait or enum of that fully qualified name, in any letter case; null for a name nothing
	 * declares, and for one whose ancestors run in a circle, which PHP refuses to load.
	 */
	public function findClass(string $name): ?ClassReflection
	{
		$provider = $this->getContainer()->getByType(ReflectionProvider::class);
		$class = $provider->hasClass($name) ? $provider->getClass($name) : null;
		return $class === null || self::hasCircularAncestors($class) ? null : $class;
	}


	/** Whether the ancestors of the class run in a circle, which PHPStan answers any question about them with an exception. */
	public static function hasCircularAncestors(ClassReflection $class): bool
	{
		try {
			$class->getParents();
			$class->getInterfaces();
			return false;
		} catch (CircularReference) {
			return true;
		}
	}


	/** The type written as a phpDoc writes it, its classes fully qualified. */
	public function resolveType(string $type): Type
	{
		return $this->getContainer()->getByType(TypeStringResolver::class)->resolve($type);
	}


	private function getContainer(): Container
	{
		if ($this->container !== null) {
			ContainerFactory::postInitializeContainer($this->container);
			return $this->container;
		}

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
				singleReflectionFile: $this->replacement[1] ?? null,
				singleReflectionInsteadOfFile: $this->replacement[0] ?? null,
			);
			// the container does not run them, the command of PHPStan does; an extension such as Larastan needs them
			foreach ($container->getParameter('bootstrapFiles') as $file) {
				(static function (string $file): void { require_once $file; })($file);
			}
		} catch (\Throwable $e) {
			throw new \RuntimeException('PHPStan could not be started' . ($config ? " with $config" : '') . ": {$e->getMessage()}", previous: $e);
		}

		return $this->container = $container;
	}


	/**
	 * The configurations of the extensions phpstan/extension-installer registered, which the command of PHPStan
	 * includes before the one of the project.
	 * @return list<string>
	 */
	private static function findExtensionConfigs(): array
	{
		$class = 'PHPStan\ExtensionInstaller\GeneratedConfig';
		if (!class_exists($class)) {
			return [];
		}

		$generated = new \ReflectionClass($class);
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


	/**
	 * The classes, interfaces, traits and enums the code declares by name, each with its parents, the interfaces it
	 * implements, the traits it uses and the names of its members, which is what the reflection PHPStan reads answers
	 * about; the signatures of the members are left out, a question about them being read from the text of the pass.
	 * @param  array<\PhpParser\Node\Stmt>  $ast
	 * @return list<list<string>>
	 */
	private static function collectDeclarations(array $ast): array
	{
		$declarations = [];
		foreach ((new NodeFinder)->findInstanceOf($ast, ClassLike::class) as $class) {
			if (!isset($class->namespacedName)) { // an anonymous class has none
				continue;
			}

			$names = match (true) {
				$class instanceof Class_ => [$class->extends, ...$class->implements],
				$class instanceof Interface_ => $class->extends,
				$class instanceof Enum_ => $class->implements,
				default => [],
			};
			foreach ($class->getTraitUses() as $use) {
				$names = [...$names, ...$use->traits];
			}

			$declaration = [$class->namespacedName->toLowerString()];
			foreach ($names as $name) {
				$declaration[] = $name?->toLowerString() ?? '';
			}

			$members = [];
			foreach ($class->getMethods() as $method) {
				$members[] = $method->name->toLowerString() . '()';
				foreach ($method->params as $param) {
					$members[] = $param->flags !== 0 && $param->var instanceof Variable && is_string($param->var->name) ? '$' . $param->var->name : null;
				}
			}

			foreach ($class->getProperties() as $property) {
				foreach ($property->props as $item) {
					$members[] = '$' . $item->name->toString();
				}
			}

			foreach ($class->getConstants() as $constant) {
				foreach ($constant->consts as $item) {
					$members[] = $item->name->toString();
				}
			}

			foreach ($class->stmts as $stmt) {
				$members[] = $stmt instanceof EnumCase ? $stmt->name->toString() : null;
			}

			$members = array_filter($members);
			sort($members);
			$declarations[] = [...$declaration, ...$members];
		}

		return $declarations;
	}


	private function toAbsolutePath(string $path): string
	{
		return FileSystem::isAbsolute($path) ? $path : Helpers::canonicalizePath($this->root) . '/' . $path;
	}
}
