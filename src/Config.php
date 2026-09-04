<?php declare(strict_types=1);

namespace DressCode;

use PhpSyntax\Nodes\FileNode;
use function is_callable, is_int, is_string;


/**
 * The configuration of a project, written as dresscode.neon or dresscode.php, whose keys are the parameters here: the
 * profile of the whole project, the overrides for parts of its tree, and the scope of a run, what it checks and what it
 * keeps. A key left out keeps its default.
 */
final readonly class Config extends Profile
{
	/** dependencies, temporary and log directories, and anything dot-prefixed (.git, .idea, .scratch) */
	public const DefaultExcludePaths = ['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'];

	/** the oldest version DressCode fixes code for; the rules never ask whether the target has what it already has */
	public const MinPhpVersion = '8.0';

	/** the version the rules target when neither the configuration nor a composer.json says one */
	public const DefaultPhpVersion = '8.0';

	/** @var list<string>  patterns relative to the root: the default ones and those the configuration adds, each once */
	public array $excludePaths;

	/** @var ?\Closure(string, string): bool  files left out by their content and path */
	public ?\Closure $skipWhen;

	/** @var array<class-string, ?\Closure(FileNode): object>  analysis → its factory, or null when the engine builds it with the file or with nothing */
	public array $analyses;


	/**
	 * @param list<string> $presets
	 * @param list<string|Group> $groups
	 * @param array<string, bool|string|int|array<string, mixed>|\Closure(): Rule> $rules
	 * @param array{functions?: list<string>, constants?: list<string>} $namespaces
	 * @param list<string> $fixRisky
	 * @param list<string> $warnings
	 * @param list<string> $excludePaths  left out of the run on top of the default list
	 * @param ?callable(string $content, string $path): bool $skipWhen
	 * @param array<string|int, string|callable(FileNode): object> $analyses  a class the engine builds itself, or a class with its factory
	 */
	public function __construct(
		/** @var list<string|Extension>  classes of extensions, and rules and presets made known by their names; a run looks them up */
		public array $extensions = [],
		array $presets = [],
		array $groups = [],
		array $rules = [],
		int|string|null $indent = null,
		?string $eol = null,
		int|false|null $lineLength = null,
		?string $php = null,
		array $namespaces = [],
		?string $nameResolution = null,
		array $fixRisky = [],
		array $warnings = [],
		/** @var list<Override> */
		public array $overrides = [],
		/** @var list<string>  files and directories relative to the root, checked when the command line names none */
		public array $paths = [],
		array $excludePaths = [],
		/** @var list<string>  the extensions of the files to check, without a dot */
		public array $fileExtensions = ['php'],
		?callable $skipWhen = null,
		/** .neon or .php file of violations left unreported, relative to the root; `check --generate-baseline` writes it */
		public ?string $baseline = null,
		array $analyses = [],
	) {
		parent::__construct($presets, $groups, $rules, $indent, $eol, $lineLength, $php, $namespaces, $nameResolution, $fixRisky, $warnings);
		$this->excludePaths = array_values(array_unique([...self::DefaultExcludePaths, ...$excludePaths]));
		$this->skipWhen = $skipWhen === null ? null : $skipWhen(...);
		$this->analyses = self::normalizeAnalyses($analyses);
	}


	/**
	 * @param  array<string|int, string|callable(FileNode): object>  $analyses
	 * @return array<class-string, ?\Closure(FileNode): object>
	 */
	private static function normalizeAnalyses(array $analyses): array
	{
		$normalized = [];
		foreach ($analyses as $key => $value) {
			[$class, $factory] = is_int($key) ? [$value, null] : [$key, $value];
			if (!is_string($class) || !class_exists($class)) {
				throw new \InvalidArgumentException('Analysis class ' . (is_string($class) ? $class : get_debug_type($class)) . ' does not exist.');
			} elseif ($factory === null && !self::isConstructible($class)) {
				throw new \InvalidArgumentException("Analysis $class must take the FileNode or nothing in its constructor, or come with a factory.");
			} elseif ($factory !== null && !is_callable($factory)) {
				throw new \InvalidArgumentException("The factory of analysis $class must be callable, " . get_debug_type($factory) . ' given.');
			}

			$normalized[$class] = $factory === null ? null : $factory(...);
		}

		return $normalized;
	}


	/**
	 * Whether the engine can build the analysis itself, which it does with the FileNode or without arguments.
	 * @param class-string $class
	 */
	private static function isConstructible(string $class): bool
	{
		$reflection = new \ReflectionClass($class);
		$constructor = $reflection->getConstructor();
		if (!$reflection->isInstantiable()) {
			return false;
		} elseif ($constructor === null || $constructor->getNumberOfParameters() === 0) {
			return true;
		}

		$type = $constructor->getParameters()[0]->getType();
		return $constructor->getNumberOfRequiredParameters() <= 1
			&& $type instanceof \ReflectionNamedType
			&& is_a($type->getName(), FileNode::class, allow_string: true);
	}
}
