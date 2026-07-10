<?php declare(strict_types=1);

namespace DressCode;

use PhpSyntax\Nodes\FileNode;
use PhpSyntax\PhpVersion;


/**
 * Configuration of a run, built fluently in dresscode.php. A setting left untouched keeps its default,
 * so layers merge by what they set.
 */
final class Config
{
	/** dependencies, temporary and log directories, and anything dot-prefixed (.git, .idea, .scratch) */
	public const DefaultSkip = ['vendor', 'node_modules', 'temp', 'tmp', 'log', '.*'];

	/** @var list<string>  names or classes */
	private array $presets = [];

	/** @var array<string, bool|array<string, mixed>|\Closure(): Rule>  rule name or class → enabled, options or a factory, in the order of the first mention */
	private array $rules = [];

	/** @var PhpVersion|'auto'|null  'auto' means the version of composer.json */
	private string|PhpVersion|null $phpVersion = null;
	private ?string $indent = null;

	/** 'auto' means the prevailing line ending of each file */
	private ?string $eol = null;

	/** @var ?list<string> */
	private ?array $paths = null;

	/** @var ?list<string> */
	private ?array $skip = null;

	/** @var array<string, list<string>>  rule name → patterns */
	private array $ruleSkip = [];

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


	/** @param string $preset  name or class */
	public function preset(string $preset): static
	{
		$this->presets[] = $preset;
		return $this;
	}


	/** @param string|PhpVersion $version  'auto', '8.2' or a version */
	public function phpVersion(string|PhpVersion $version): static
	{
		$this->phpVersion = !is_string($version)
			? $version
			: ($version === 'auto' ? 'auto' : PhpVersion::fromString($version));
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
	 * @param ?string $indent  the indentation unit
	 * @param ?string $eol  "\n", "\r\n" or 'auto'
	 */
	public function style(?string $indent = null, ?string $eol = null): static
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
	 * Replaces the default skip list. Patterns under a string key apply to that rule only.
	 * @param array<int|string, string|list<string>> $skip  pattern, or rule name → patterns
	 */
	public function skip(array $skip): static
	{
		$this->skip = [];
		foreach ($skip as $key => $value) {
			if (is_string($key)) {
				$this->ruleSkip[$key] = array_merge($this->ruleSkip[$key] ?? [], (array) $value);
			} elseif (is_string($value)) {
				$this->skip[] = $value;
			} else {
				throw new ConfigurationException('Skip patterns must be strings, or rule name => patterns.');
			}
		}

		return $this;
	}


	/**
	 * Adds to the skip list, the default one included.
	 * @param array<int|string, string|list<string>> $skip  pattern, or rule name → patterns
	 */
	public function addSkip(array $skip): static
	{
		$current = $this->getSkip();
		$this->skip($skip);
		$this->skip = [...$current, ...$this->skip ?? []];
		return $this;
	}


	/** @param list<string> $extensions */
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
	 */
	public function analysis(string $class, ?callable $factory = null): static
	{
		$this->analyses[$class] = $factory === null ? null : $factory(...);
		return $this;
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


	/** @return PhpVersion|'auto' */
	public function getPhpVersion(): PhpVersion|string
	{
		return $this->phpVersion ?? 'auto';
	}


	public function getIndent(): string
	{
		return $this->indent ?? "\t";
	}


	/** @return "\n"|"\r\n"|'auto' */
	public function getEol(): string
	{
		return match ($this->eol) {
			null, 'auto' => 'auto',
			"\n", "\r\n" => $this->eol,
			default => throw new ConfigurationException('The line ending must be "\n", "\r\n" or \'auto\'.'),
		};
	}


	/** @return list<string> */
	public function getPaths(): array
	{
		return $this->paths ?? [];
	}


	/** @return list<string> */
	public function getSkip(): array
	{
		return $this->skip ?? self::DefaultSkip;
	}


	/** @return array<string, list<string>> */
	public function getRuleSkip(): array
	{
		return $this->ruleSkip;
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
