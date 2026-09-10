<?php declare(strict_types=1);

namespace DressCode;

use PhpSyntax\Nodes\FileNode;
use function is_int, is_string;


/**
 * Configuration of a run, built fluently in dresscode.php or read from dresscode.neon, whose every key
 * is one of these methods. A setting left untouched keeps its default, so layers merge by what they set.
 */
final class Config
{
	/** dependencies, temporary and log directories, and anything dot-prefixed (.git, .idea, .scratch) */
	public const DefaultExcludePaths = ['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'];

	/** the version the rules target when neither the configuration nor a composer.json says one */
	public const DefaultPhpVersion = '8.0';

	/** @var list<string|\Closure(self): mixed>  closures and class names, in the order of the first mention */
	private array $extensions = [];

	/** @var list<class-string<Rule>> */
	private array $registeredRules = [];

	/** @var list<class-string<Preset>> */
	private array $registeredPresets = [];

	/** @var list<string>  names or classes */
	private array $presets = [];

	/** @var array<string, bool|array<string, mixed>|\Closure(): Rule>  rule name or class → enabled, options or a factory, in the order of the first mention */
	private array $rules = [];

	/** 'auto' means the version of composer.json */
	private ?string $phpVersion = null;
	private int|string|null $indent = null;
	private ?string $eol = null;

	/** @var ?list<string> */
	private ?array $paths = null;

	/** @var list<string>  what the default list is extended with */
	private array $excludePaths = [];

	/** @var array<string, list<string>>  rule name → patterns */
	private array $ruleExcludePaths = [];

	/** @var ?list<string>  names or classes of the rules that only warn */
	private ?array $warnings = null;

	/** @var list<array{list<string>, array<string, bool|array<string, mixed>>}>  patterns and the rules of that part of the tree */
	private array $blocks = [];

	/** @var ?list<string> */
	private ?array $fileExtensions = null;

	/** @var ?\Closure(string, string): bool */
	private ?\Closure $skipWhen = null;
	private ?string $baseline = null;
	private ?string $cacheDir = null;

	/** @var array<class-string, ?\Closure(FileNode): object> */
	private array $analyses = [];


	public static function create(): self
	{
		return new self;
	}


	/**
	 * Sets up a layer below this one: whatever the extension sets, this configuration may override. The
	 * layer is built when the configuration is resolved, so the order of the calls does not matter.
	 * @param string|callable(self): mixed $extension  a callable, or the name of an invokable class
	 */
	public function extension(callable|string $extension): static
	{
		$this->extensions[] = is_string($extension) ? $extension : $extension(...);
		return $this;
	}


	/**
	 * Makes the rules known by their names, without enabling them.
	 * @param list<class-string<Rule>> $classes
	 */
	public function registerRules(array $classes): static
	{
		$this->registeredRules = [...$this->registeredRules, ...$classes];
		return $this;
	}


	/**
	 * Makes the presets known by their names.
	 * @param list<class-string<Preset>> $classes
	 */
	public function registerPresets(array $classes): static
	{
		$this->registeredPresets = [...$this->registeredPresets, ...$classes];
		return $this;
	}


	/** @param string $preset  name or class */
	public function preset(string $preset): static
	{
		$this->presets[] = $preset;
		return $this;
	}


	/** @param string $version  'auto' or a version as major.minor */
	public function phpVersion(string $version): static
	{
		if ($version !== 'auto' && !preg_match('~^\d+\.\d+(?:\.\d+)?$~D', $version)) {
			throw new \InvalidArgumentException("Invalid PHP version '$version'.");
		}

		$this->phpVersion = $version;
		return $this;
	}


	/**
	 * @param string $rule  name or class
	 * @param bool|array<string, mixed>|\Closure(): Rule $options  options, or a factory for a rule with dependencies
	 */
	public function enable(string $rule, bool|array|\Closure $options = true): static
	{
		$this->rules[$rule] = $options;
		return $this;
	}


	/** @param string $rule  name or class */
	public function disable(string $rule): static
	{
		$this->rules[$rule] = false;
		return $this;
	}


	/**
	 * @param int|string|null $indent  a number of spaces or 'tab'
	 * @param ?string $eol  'lf', 'crlf', 'majority' or 'platform'
	 */
	public function style(int|string|null $indent = null, ?string $eol = null): static
	{
		$this->indent = $indent ?? $this->indent;
		$this->eol = $eol ?? $this->eol;
		return $this;
	}


	/** @param list<string> $paths  files and directories, relative to the root */
	public function paths(array $paths): static
	{
		$this->paths = $paths;
		return $this;
	}


	/**
	 * Paths left out of the run, on top of the default list; every layer adds to it, none replaces it.
	 * @param list<string> $paths  patterns, relative to the root
	 */
	public function excludePaths(array $paths): static
	{
		$this->excludePaths = [...$this->excludePaths, ...$paths];
		return $this;
	}


	/**
	 * Paths the rule is not applied to; the file is checked by the rest of the rules.
	 * @param string $rule  name or class
	 * @param list<string> $paths  patterns, relative to the root
	 */
	public function excludeRulePaths(string $rule, array $paths): static
	{
		$this->ruleExcludePaths[$rule] = [...$this->ruleExcludePaths[$rule] ?? [], ...$paths];
		return $this;
	}


	/**
	 * Other rules for a part of the tree: a file the patterns match gets these on top of everything above,
	 * in the order the blocks were written. A part of a project with a convention of its own needs another
	 * number, not a rule turned off everywhere.
	 * @param list<string> $files  patterns, relative to the root
	 * @param array<string, bool|array<string, mixed>> $rules  names or classes, as `rules` takes them
	 */
	public function for(array $files, array $rules): static
	{
		$this->blocks[] = [$files, $rules];
		return $this;
	}


	/**
	 * Rules that only warn: what they report is counted and printed, but the exit code stays clean unless
	 * `--max-warnings` sets a threshold. Every rule is an error until a layer softens it.
	 * @param list<string> $rules  names or classes
	 */
	public function warnings(array $rules): static
	{
		$this->warnings = $rules;
		return $this;
	}


	/** @param list<string> $extensions  the extensions of the files to check, without a dot */
	public function fileExtensions(array $extensions): static
	{
		$this->fileExtensions = $extensions;
		return $this;
	}


	/** @param callable(string $content, string $path): bool $predicate  files left out by their content */
	public function skipWhen(callable $predicate): static
	{
		$this->skipWhen = $predicate(...);
		return $this;
	}


	/** .neon or .php file of violations left unreported, relative to the root; `check --generate-baseline` writes it */
	public function baseline(?string $file): static
	{
		$this->baseline = $file;
		return $this;
	}


	public function cacheDir(?string $dir): static
	{
		$this->cacheDir = $dir;
		return $this;
	}


	/**
	 * @param class-string $class
	 * @param ?callable(FileNode): object $factory
	 * @throws ConfigurationException
	 */
	public function analysis(string $class, ?callable $factory = null): static
	{
		if (!class_exists($class)) {
			throw new ConfigurationException("Analysis class $class does not exist.");
		} elseif ($factory === null && !self::isConstructible($class)) {
			throw new ConfigurationException("Analysis $class must take the FileNode or nothing in its constructor, or come with a factory.");
		}

		$this->analyses[$class] = $factory === null ? null : $factory(...);
		return $this;
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


	/**
	 * Lays another configuration over this one: its presets and rules come after, its settings win when set.
	 */
	public function merge(self $layer): static
	{
		$this->extensions = array_merge($this->extensions, $layer->extensions);
		$this->registeredRules = array_merge($this->registeredRules, $layer->registeredRules);
		$this->registeredPresets = array_merge($this->registeredPresets, $layer->registeredPresets);
		$this->presets = array_merge($this->presets, $layer->presets);
		foreach ($layer->rules as $rule => $options) {
			$this->rules[$rule] = $options;
		}

		$this->phpVersion = $layer->phpVersion ?? $this->phpVersion;
		$this->indent = $layer->indent ?? $this->indent;
		$this->eol = $layer->eol ?? $this->eol;
		$this->paths = $layer->paths ?? $this->paths;
		$this->excludePaths = [...$this->excludePaths, ...$layer->excludePaths];
		foreach ($layer->ruleExcludePaths as $rule => $patterns) {
			$this->ruleExcludePaths[$rule] = [...$this->ruleExcludePaths[$rule] ?? [], ...$patterns];
		}

		$this->warnings = $layer->warnings ?? $this->warnings;
		$this->blocks = [...$this->blocks, ...$layer->blocks];
		$this->fileExtensions = $layer->fileExtensions ?? $this->fileExtensions;
		$this->skipWhen = $layer->skipWhen ?? $this->skipWhen;
		$this->baseline = $layer->baseline ?? $this->baseline;
		$this->cacheDir = $layer->cacheDir ?? $this->cacheDir;
		$this->analyses = $layer->analyses + $this->analyses;
		return $this;
	}


	/**
	 * The configuration with its extensions laid out below it, each class once and the ones it pulled in
	 * before it, ready to be read; the result has no extensions left to apply.
	 * @throws ConfigurationException
	 */
	public function resolveExtensions(): self
	{
		$base = new self;
		$visited = [];
		foreach ($this->extensions as $extension) {
			self::applyExtension($extension, $base, $visited);
		}

		$base->merge($this);
		$base->extensions = [];
		return $base;
	}


	/**
	 * @param  string|\Closure(self): mixed  $extension
	 * @param  array<string, true>  $visited
	 * @throws ConfigurationException
	 */
	private static function applyExtension(\Closure|string $extension, self $base, array &$visited): void
	{
		if (is_string($extension)) {
			if (isset($visited[$extension])) {
				return;
			}

			$visited[$extension] = true;
		}

		$layer = new self;
		self::toCallable($extension)($layer);
		foreach ($layer->extensions as $nested) {
			self::applyExtension($nested, $base, $visited);
		}

		$base->merge($layer);
	}


	/**
	 * @param  string|\Closure(self): mixed  $extension
	 * @return callable(self): mixed
	 * @throws ConfigurationException
	 */
	private static function toCallable(\Closure|string $extension): callable
	{
		if ($extension instanceof \Closure) {
			return $extension;
		} elseif (!class_exists($extension)) {
			throw new ConfigurationException("Extension class $extension does not exist.");
		}

		$object = new $extension;
		return is_callable($object)
			? $object
			: throw new ConfigurationException("Extension $extension is not callable, it needs an __invoke() method.");
	}


	/** @return list<string|\Closure(self): mixed> */
	public function getExtensions(): array
	{
		return $this->extensions;
	}


	/** @return list<class-string<Rule>> */
	public function getRegisteredRules(): array
	{
		return $this->registeredRules;
	}


	/** @return list<class-string<Preset>> */
	public function getRegisteredPresets(): array
	{
		return $this->registeredPresets;
	}


	/** @return list<string> */
	public function getPresets(): array
	{
		return $this->presets;
	}


	/** @return array<string, bool|array<string, mixed>|\Closure(): Rule> */
	public function getRules(): array
	{
		return $this->rules;
	}


	/** The configured version, or 'auto' for the one of composer.json. */
	public function getPhpVersion(): string
	{
		return $this->phpVersion ?? 'auto';
	}


	/**
	 * The configured indentation; null leaves it to the presets.
	 * @return int|'tab'|null
	 */
	public function getIndent(): int|string|null
	{
		return match (true) {
			$this->indent === null, $this->indent === 'tab' => $this->indent,
			is_int($this->indent) && $this->indent >= 1 => $this->indent,
			default => throw new ConfigurationException("The indentation must be a number of spaces or 'tab'."),
		};
	}


	/**
	 * The configured line ending; null leaves it to the presets.
	 * @return 'lf'|'crlf'|'majority'|'platform'|null
	 */
	public function getEol(): ?string
	{
		return match ($this->eol) {
			null, 'lf', 'crlf', 'majority', 'platform' => $this->eol,
			default => throw new ConfigurationException("The line ending must be 'lf', 'crlf', 'majority' or 'platform'."),
		};
	}


	/** @return list<string> */
	public function getPaths(): array
	{
		return $this->paths ?? [];
	}


	/**
	 * The default list with everything the layers added, each pattern once.
	 * @return list<string>
	 */
	public function getExcludePaths(): array
	{
		return array_values(array_unique([...self::DefaultExcludePaths, ...$this->excludePaths]));
	}


	/** @return array<string, list<string>> */
	public function getRuleExcludePaths(): array
	{
		return $this->ruleExcludePaths;
	}


	/** @return list<string> */
	public function getWarnings(): array
	{
		return $this->warnings ?? [];
	}


	/** @return list<array{list<string>, array<string, bool|array<string, mixed>>}> */
	public function getBlocks(): array
	{
		return $this->blocks;
	}


	/** @return list<string> */
	public function getFileExtensions(): array
	{
		return $this->fileExtensions ?? ['php'];
	}


	/** @return ?\Closure(string, string): bool */
	public function getSkipWhen(): ?\Closure
	{
		return $this->skipWhen;
	}


	public function getBaseline(): ?string
	{
		return $this->baseline;
	}


	public function getCacheDir(): ?string
	{
		return $this->cacheDir;
	}


	/** @return array<class-string, ?\Closure(FileNode): object> */
	public function getAnalyses(): array
	{
		return $this->analyses;
	}
}
