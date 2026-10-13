<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use DressCode\Config\ComposerJson;
use DressCode\Engine\Helpers;
use DressCode\Violation;
use Nette\Utils\FileSystem;
use PhpParser\{Node, NodeFinder};
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\{Class_, ClassLike, Enum_, EnumCase, Interface_};
use PHPStan\Analyser\{NodeScopeResolver, Scope, ScopeContext, ScopeFactory};
use PHPStan\BetterReflection\Reflection\Exception\CircularReference;
use PHPStan\DependencyInjection\{Container, ContainerFactory};
use PHPStan\ExtensionInstaller\GeneratedConfig;
use PHPStan\Parser\Parser;
use PHPStan\PharAutoloader;
use PHPStan\Php\PhpVersionFactory;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\BetterReflection\BetterReflectionProvider;
use PHPStan\Reflection\{ClassReflection, FunctionReflection, ReflectionProvider};
use PHPStan\Type\Type;
use PhpSyntax\Trivia;
use function ini_get, is_string, strlen;


/**
 * PHPStan in the process, the `phpstan/phpstan` installed beside DressCode: a container built once from
 * the configuration of the project, the parser and the scope resolver the types come from. Only what PHPStan
 * marks @api is used, so that a minor version of it changes nothing here, except the newest version of PHP it
 * accepts and two things about the reflection living in static state of PHPStan: the container created last takes it
 * over, and handing it back to another container is internal, as PHPStan does it itself where it switches between
 * two; and its cache of anonymous classes holds the container that reflected each, so it is emptied before a PHPStan
 * of another text of a pass is made.
 * @internal
 */
final class PhpStan
{
	private const ConfigFiles = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

	private ?Container $container = null;

	/** @var ?array{string, string}  the path whose declarations are read from the other file, a text of a pass */
	private ?array $replacement = null;

	/** @var array<string, array{string, list<list<string>>}>  path => hash of its text on the disk and what it declares */
	private array $diskDeclarations = [];

	/** @var ?array{string, self}  hash of the text of a pass and the PHPStan reading it */
	private ?array $derived = null;

	private ?Declarations $declarations = null;

	private ?ClassLoader $autoloader = null;

	/** @var ?list<string>  the files the configuration read, once a container is built */
	private ?array $configurationFiles = null;

	/** that the types go without the configuration of the project, which PHPStan could not start with */
	private ?string $configurationWarning = null;


	public function __construct(
		private readonly string $root,
		/** @var list<string> where the classes of the project are declared, besides its Composer autoload */
		private readonly array $analysedPaths,
		private readonly string $tempDir,
		/** the version of PHP the code is written for, which PHPStan parses it as where the running PHP is older */
		private readonly ?string $phpVersion = null,
		/**
		 * @var list<string>  the directories of the packages installed in a newer version than the oldest one the project
		 * supports, whose declarations may say what that one does not
		 */
		private readonly array $newerPackagePaths = [],
		/** @var list<string>  the directories of the packages the project supports from no particular version */
		private readonly array $unboundedPackagePaths = [],
		/**
		 * @var list<string>  the directories whose declarations are none of the project's, the installation DressCode runs
		 * from, whose packages the process loads for itself and PHPStan finds through its autoloaders
		 */
		private readonly array $foreignPaths = [],
	) {
		self::claimPhpDocParser();
	}


	public function __destruct()
	{
		if ($this->replacement !== null) {
			@unlink($this->replacement[1]); // @ file may have been deleted meanwhile
		}
	}


	/**
	 * Whether PHPStan is installed beside DressCode, in the vendor the process runs from: the analysis is available
	 * only then, whatever the project has. Composer is asked, not the autoloader, which would open the archive of
	 * PHPStan in every run without the types.
	 */
	public static function isAvailable(): bool
	{
		return InstalledVersions::isInstalled('phpstan/phpstan');
	}


	/**
	 * Makes the copy of phpdoc-parser inside the archive of PHPStan, the only one PHPStan is tested with, the one the
	 * process loads its classes from. The autoloader of the archive, which PHPStan registers in front of the others once it
	 * starts, would otherwise mix it with the classes loaded before. The file is the one the bootstrap of PHPStan loads,
	 * whose loader is created once.
	 */
	public static function claimPhpDocParser(): void
	{
		if (class_exists(PharAutoloader::class)) {
			$dir = dirname((string) new \ReflectionClass(PharAutoloader::class)->getFileName());
			require "phar://$dir/phpstan.phar/vendor/autoload.php";
		}
	}


	/**
	 * A class of phpdoc-parser the process loaded from outside the archive of PHPStan before claiming it, which the
	 * classes loaded since then mix with; null where there is none.
	 */
	public static function findMixedPhpDocParserClass(): ?string
	{
		if (!class_exists(PharAutoloader::class)) {
			return null;
		}

		foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $class) {
			if (
				str_starts_with($class, 'PHPStan\PhpDocParser\\')
				&& !str_starts_with((string) new \ReflectionClass($class)->getFileName(), 'phar://')
			) {
				return $class;
			}
		}

		return null;
	}


	/**
	 * @return array<Stmt>
	 * @throws UncompilableCodeException  for code PHP would not compile, two imports under one name
	 */
	public function parse(string $code): array
	{
		// defaultAnalysisParser routes a string to the simple parser, which drops the bodies of functions
		$parser = $this->getContainer()->getService('currentPhpVersionRichParser');
		assert($parser instanceof Parser);
		try {
			return $parser->parseString($code);
		} catch (\Exception $e) { // dresscode:ignore mistakes.exceptionWhereThrowableBelongs -- an Error is a bug, not code PHP refuses
			throw new UncompilableCodeException($e->getMessage(), previous: $e);
		}
	}


	/**
	 * The PHPStan for a text of the file other than the one on the disk, from which PHPStan reads the declarations
	 * of the file: this one where the text declares the same classes with the same parents, interfaces and traits,
	 * otherwise one reading the declarations of the file from the text, as the editor mode of PHPStan does. A class
	 * whose parent another file of the run renamed still has the hierarchy the disk gives it.
	 * @param  \Closure(): array<Stmt>  $parse  the text parsed, asked only where it is not the disk
	 */
	public function deriveFor(string $path, string $code, \Closure $parse): self
	{
		$path = Helpers::toAbsolutePath($path, $this->root);
		$disk = @file_get_contents($path); // @ the file may not exist, as the code of stdin does not
		if ($disk === $code) {
			return $this;
		}

		$diskHash = hash('xxh128', (string) $disk);
		if (($this->diskDeclarations[$path][0] ?? null) !== $diskHash) {
			$this->diskDeclarations[$path] = [$diskHash, $disk === false ? [] : self::collectDeclarations($this->parse($disk))];
		}

		if ($this->diskDeclarations[$path][1] === self::collectDeclarations($parse())) {
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
			$derived->declarations = null;
			$this->derived = [$hash, $derived];
		}

		return $this->derived[1];
	}


	/** What the declarations this PHPStan reads say of the classes, asked by their names and kept as long as it lives. */
	public function getDeclarations(): Declarations
	{
		return $this->declarations ??= new Declarations($this);
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
	 * @param  array<Stmt>  $ast
	 * @param  callable(Node, Scope): void  $callback
	 * @throws TypesUnavailableException  when PHPStan fails on the file, the nodes after the failure having no scope
	 */
	public function resolveScopes(string $path, array $ast, callable $callback): void
	{
		$path = Helpers::toAbsolutePath($path, $this->root);
		$path = $this->replacement !== null && $path === $this->replacement[0] ? $this->replacement[1] : $path;
		$container = $this->getContainer();
		$resolver = $container->getByType(NodeScopeResolver::class);
		$resolver->setAnalysedFiles([$path]);
		$scope = $container->getByType(ScopeFactory::class)->create(ScopeContext::create($path));
		$inCallback = false;
		// a warning of PHPStan is the first sign of its failure on the file, and printed it would spoil the output; one it
		// silences is not, a rename into its cache another process has just written being one
		set_error_handler(
			fn(int $severity, string $message, string $file, int $line) => (error_reporting() & $severity)
				&& str_contains($file, '/phpstan.phar/')
				? throw new \ErrorException($message, 0, $severity, $file, $line)
				: false,
			E_WARNING,
		);
		try {
			$resolver->processNodes($ast, $scope, function (Node $node, Scope $scope) use ($callback, &$inCallback): void {
				$inCallback = true;
				$callback($node, $scope);
				$inCallback = false;
			});
		} catch (CircularReference) {
		} catch (\Throwable $e) { // an assertion or a TypeError of PHPStan on syntax it does not support costs the file its types, not the run
			// @phpstan-ignore ternary.alwaysFalse (the callback sets it by reference, which PHPStan does not follow)
			throw $inCallback ? $e : new TypesUnavailableException($e->getMessage(), previous: $e);
		} finally {
			restore_error_handler();
		}
	}


	/**
	 * The declared spelling of a class, interface or enum, with `$traits` also of a trait, the project, its packages or
	 * PHP declare, given its fully qualified name in any letter case without a leading backslash; null for a name
	 * nothing declares or PHP refuses to load.
	 */
	public function findClassName(string $name, bool $traits = false): ?string
	{
		$class = $this->findClass($name);
		return $class === null || (!$traits && $class->isTrait()) ? null : $class->getName();
	}


	/**
	 * The declared spelling of a function the project, its packages or PHP declare, given its fully qualified name in
	 * any letter case without a leading backslash; null for a name nothing declares.
	 */
	public function findFunctionName(string $name): ?string
	{
		return $this->findFunction($name)?->getName();
	}


	/**
	 * The function of that fully qualified name, in any letter case; null for a name nothing declares but a package of
	 * the installation DressCode runs from.
	 */
	public function findFunction(string $name): ?FunctionReflection
	{
		$provider = $this->getContainer()->getByType(ReflectionProvider::class);
		$node = new FullyQualified($name);
		$function = $provider->hasFunction($node, null) ? $provider->getFunction($node, null) : null;
		return $function === null || self::isInPackages($function->getFileName(), $this->foreignPaths) ? null : $function;
	}


	/** Whether something declares the global or namespaced constant of that fully qualified name. */
	public function hasConstant(string $name): bool
	{
		return $this->getContainer()->getByType(ReflectionProvider::class)->hasConstant(new FullyQualified($name), null);
	}


	/**
	 * The class, interface, trait or enum of that fully qualified name, in any letter case; null for a name nothing
	 * declares but a package of the installation DressCode runs from, and for one whose ancestors run in a circle, which
	 * PHP refuses to load.
	 */
	public function findClass(string $name): ?ClassReflection
	{
		$provider = $this->getContainer()->getByType(ReflectionProvider::class);
		$class = $provider->hasClass($name) ? $provider->getClass($name) : null;
		return $class === null
			|| self::isInPackages($class->getFileName(), $this->foreignPaths)
			|| self::hasCircularAncestors($class)
			? null
			: $class;
	}


	/**
	 * The file the Composer autoload of the project finds the class in, without loading it; null where it finds none.
	 * The reflection cannot say it for a name the process has made an alias of another class, as a package does for
	 * an old name with `class_alias()`: PHPStan in the process loads its own copy of nikic/php-parser, and DressCode
	 * its own nette/utils, whose aliases it then reflects as the class aliased.
	 */
	public function findAutoloadedFile(string $class): ?string
	{
		if ($this->autoloader === null) {
			$composer = ComposerJson::read(ComposerJson::findFile($this->root));
			$vendorDir = $composer['config']['vendor-dir'] ?? 'vendor';
			$vendor = Helpers::toAbsolutePath(is_string($vendorDir) ? $vendorDir : 'vendor', $this->root) . '/composer';
			$this->autoloader = new ClassLoader;
			foreach (is_file("$vendor/autoload_psr4.php") ? require "$vendor/autoload_psr4.php" : [] as $prefix => $paths) {
				$this->autoloader->setPsr4($prefix, $paths);
			}

			foreach (is_file("$vendor/autoload_namespaces.php") ? require "$vendor/autoload_namespaces.php" : [] as $prefix => $paths) {
				$this->autoloader->set($prefix, $paths);
			}

			$this->autoloader->addClassMap(is_file("$vendor/autoload_classmap.php") ? require "$vendor/autoload_classmap.php" : []);
		}

		$file = $this->autoloader->findFile($class);
		return is_string($file) ? $file : null;
	}


	/**
	 * What the doc comment of the declaration of the class in the file says under `@deprecated`, an empty string for
	 * the bare annotation; null where the file declares no such class or the declaration is not deprecated.
	 */
	public function findDeclaredDeprecation(string $class, string $file): ?string
	{
		try {
			$code = @file_get_contents($file); // @ the file may be gone
			$ast = $code === false ? [] : $this->parse($code);
		} catch (UncompilableCodeException) {
			$ast = []; // a file of a package is no concern of the file of the run
		}

		$declaration = (new NodeFinder)->findFirst(
			$ast,
			fn($node) => $node instanceof ClassLike && strcasecmp((string) $node->namespacedName, $class) === 0,
		);
		$docComment = $declaration?->getDocComment()?->getText();
		$tags = $docComment === null
			? []
			: (new PhpDoc)->parse(new Trivia(Trivia::DocComment, $docComment))->getDeprecatedTagValues();
		return $tags === [] ? null : (string) $tags[0]->description;
	}


	/** Whether the file is one of the tests of the project: a `.phpt`, or a file under `tests` or `test` of the root. */
	public function isTestFile(string $file): bool
	{
		$file = $this->resolvePath($file);
		$root = Helpers::canonicalizePath(FileSystem::normalizePath($this->root)) . '/';
		return str_starts_with(strtolower($file), strtolower($root))
			&& (str_ends_with($file, '.phpt') || preg_match('~^tests?/~i', substr($file, strlen($root))) === 1);
	}


	/** Whether the two paths, either of them relative to the root, are one file. */
	public function isSameFile(string $a, string $b): bool
	{
		return strcasecmp($this->resolvePath($a), $this->resolvePath($b)) === 0;
	}


	private function resolvePath(string $file): string
	{
		return Helpers::canonicalizePath(FileSystem::normalizePath(FileSystem::isAbsolute($file) ? $file : "$this->root/$file"));
	}


	/**
	 * Whether the file belongs to a package installed in a newer version than the oldest one the project supports, so
	 * that what a declaration in it deprecates may not be deprecated, nor its replacement there, in the version the code
	 * must work with too.
	 */
	public function isAheadOfSupport(?string $file): bool
	{
		return self::isInPackages($file, $this->newerPackagePaths);
	}


	/**
	 * Whether the file belongs to a package the project supports from no particular version, so that what a declaration
	 * in it deprecates holds for the installed version only, and its replacement may not be there in another one.
	 */
	public function isUnbounded(?string $file): bool
	{
		return self::isInPackages($file, $this->unboundedPackagePaths);
	}


	/** @param  list<string>  $paths */
	private static function isInPackages(?string $file, array $paths): bool
	{
		if ($file === null || $paths === []) {
			return false;
		}

		$file = strtolower(Helpers::canonicalizePath(FileSystem::normalizePath($file))); // the reflection gives `vendor/composer/../`
		return array_any(
			$paths,
			fn(string $path) => str_starts_with($file, strtolower(Helpers::canonicalizePath($path)) . '/'),
		);
	}


	/**
	 * Builds the container and fills the cache of the reflection: a class nothing declares is looked for in every
	 * source of declarations, and each writes what it holds.
	 */
	public function warmUp(): void
	{
		$this->findClass('DressCode\Analyses\PhpStan\WarmUp');
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


	/**
	 * The files the configuration of PHPStan read, the included ones, the stubs and the bootstrap files among them,
	 * those of PHPStan itself and those written in the temporary directory left out; null while no container is built.
	 * What they declare outside the analysed paths is what the stamps of those paths do not see.
	 * @return ?list<string>
	 */
	public function getConfigurationFiles(): ?array
	{
		return $this->configurationFiles ?? $this->derived[1]->configurationFiles ?? null;
	}


	/**
	 * What the run should be told of PHPStan: that the configuration of the project could not be loaded, once a container
	 * is built without it.
	 * @return list<string>
	 */
	public function getWarnings(): array
	{
		return $this->configurationWarning === null ? [] : [$this->configurationWarning];
	}


	/**
	 * The container of the configuration of the project, or of none where PHPStan cannot start with it, an extension it
	 * loads from a vendor directory of another installation above all, so that the types are those of the code alone.
	 */
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

		// PHPStan finds a class the project lacks by calling the autoloaders over a trapped stream wrapper and invalidates
		// what opcache compiled of that empty read, which it cannot do in a file cache alone, where every later run finds it
		if (ini_get('opcache.file_cache_only')) {
			ini_set('opcache.enable', '0');
		}
		$container = $failure = null;
		foreach (array_unique([$config, null]) as $file) {
			try {
				$container = $this->createContainer($file);
				break;
			} catch (\Throwable $e) {
				$failure ??= $e;
			}
		}

		if ($container === null) {
			throw new \RuntimeException(
				'PHPStan could not be started' . ($config ? ' with ' . Violation::formatCode($config) : '') . ": {$failure->getMessage()}",
				previous: $failure,
			);
		} elseif ($failure !== null && $config !== null) {
			$this->configurationWarning = 'PHPStan could not start with ' . Violation::formatCode(basename($config))
				. ' of the project: ' . strtok($failure->getMessage(), "\n") . '; the types go without it.';
		}

		$this->configurationFiles ??= $this->collectConfigurationFiles($container);
		return $this->container = $container;
	}


	/** The container of the configuration file, with the bootstrap files it names run. */
	private function createContainer(?string $config): Container
	{
		$container = new ContainerFactory($this->root)->create(
			$this->tempDir,
			[...self::findExtensionConfigs(), ...array_filter([$this->writeVersionConfig(), $config])],
			$this->analysedPaths,
			[$this->root],
			singleReflectionFile: $this->replacement[1] ?? null,
			singleReflectionInsteadOfFile: $this->replacement[0] ?? null,
		);
		// the container does not run them, the command of PHPStan does; an extension such as Larastan needs them
		foreach ($container->getParameter('bootstrapFiles') as $file) {
			(static function (string $file): void { require_once $file; })($file);
		}

		return $container;
	}


	/** @return list<string> */
	private function collectConfigurationFiles(Container $container): array
	{
		$temp = Helpers::canonicalizePath($this->tempDir) . '/';
		$files = [];
		foreach (['allConfigFiles', 'stubFiles', 'bootstrapFiles'] as $parameter) {
			foreach ($container->getParameter($parameter) as $file) {
				$file = Helpers::canonicalizePath((string) $file);
				if (!str_starts_with($file, 'phar://') && !str_starts_with($file, $temp)) {
					$files[$file] = true;
				}
			}
		}

		return array_keys($files);
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
		/**
		 * @var array<array{relative_install_path: string, extra: array{includes?: list<string>}}> $extensions  generated
		 * for the project
		 */
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
	 * The configuration giving PHPStan the version of the code where the running PHP is older, whose parser would
	 * refuse the syntax of the newer one, capped at the newest version PHPStan accepts; a version the configuration
	 * of the project sets comes after it and wins.
	 */
	private function writeVersionConfig(): ?string
	{
		if ($this->phpVersion === null) {
			return null;
		}

		[$major, $minor, $patch] = array_map(intval(...), explode('.', $this->phpVersion)) + [0, 0, 0];
		$id = min($major * 10000 + $minor * 100 + $patch, PhpVersionFactory::MAX_PHP_VERSION);
		if ($id <= PHP_VERSION_ID) {
			return null;
		}

		// shared by every process, PHPStan keying its container by the files, so written once and atomically
		$file = Helpers::canonicalizePath($this->tempDir) . "/php-$id.neon";
		if (!is_file($file)) {
			FileSystem::writeAtomic($file, "parameters:\n\tphpVersion: $id\n");
		}

		return $file;
	}


	/**
	 * The classes, interfaces, traits and enums the code declares by name, each with its parents, the interfaces it
	 * implements, the traits it uses and the names of its members, which is what the reflection PHPStan reads answers
	 * about; the signatures of the members are left out, a question about them being read from the text of the pass.
	 * @param  array<Stmt>  $ast
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
					$members[] = $param->flags !== 0 && $param->var instanceof Variable && is_string($param->var->name)
						? '$' . $param->var->name
						: null;
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
}
