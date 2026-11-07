<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\Config\{ComposerJson, PluginRegistry};
use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function in_array, strlen;


/**
 * The configuration of the formatter a project has, found where a project keeps it. What `dresscode init` and
 * `dresscode migrate config` take of it is read as data and never run: the standard it names, the paths it checks and
 * those it leaves out, the paths a rule of it leaves out, and how the project runs it. Only its rules, which
 * `translate()` reads, need the configuration of PHP CS Fixer run, being PHP.
 * @internal
 */
final readonly class FormatterConfig
{
	private function __construct(
		public Formatter $formatter,
		/** relative to the root, absolute where it lies outside */
		public string $file,
		/** the command of the project that runs it, `composer check`; null where none does */
		public ?string $runBy,
		/** the standard of DressCode its set or preset is; null for none */
		public ?string $standard,
		/** @var list<string>  the directories and files it checks, relative to the root; empty where it names none */
		public array $paths,
		/** @var list<string>  regular expressions of the paths, relative to the root, it leaves out */
		public array $excludePatterns,
		/** @var array<string, list<string>>  foreign rule => regular expressions of the paths it leaves out */
		public array $ruleExcludePatterns,
		/** absolute */
		private string $path,
		/** @var list<string>  what of the configuration could be neither read nor left aside as running it only, `in($dirs)` */
		public array $unread = [],
		/** @var list<string>  regular expressions of the names of the files it leaves out */
		public array $nameExcludePatterns = [],
		/** @var ?list<string>  the extensions of the files it checks, without a dot; null where it names none */
		public ?array $extensions = null,
	) {
	}


	/** The configuration in the file given, relative to the root or absolute, the formatter told by its extension. */
	public static function fromFile(string $root, string $file): self
	{
		$file = Helpers::canonicalizePath($file);
		$root = Helpers::canonicalizePath($root);
		$file = str_starts_with($file, "$root/") ? substr($file, strlen($root) + 1) : $file;
		return self::read($root, $file, Formatter::fromFileName($file));
	}


	/** The configuration of the formatter in the file given, relative to the root or absolute, and how the project runs it. */
	public static function read(string $root, string $file, Formatter $formatter, ?string $runBy = null): self
	{
		$path = FileSystem::isAbsolute($file) ? $file : "$root/$file";
		return match ($formatter) {
			Formatter::PhpCodeSniffer => self::readPhpCodeSniffer($root, $path, $file, $runBy),
			Formatter::PhpCsFixer => self::readPhpCsFixer($root, $path, $file, $runBy),
			Formatter::Pint => self::readPint($path, $file, $runBy),
		};
	}


	/** Laravel Pint: the standard of its preset, `laravel` where it names none, and the directories it leaves out. */
	private static function readPint(string $path, string $file, ?string $runBy): self
	{
		// a file that is no JSON names no preset, which is not the default preset of a file naming none
		$config = ComposerJson::read($path);
		$excludes = array_map(
			fn($dir) => '~^' . preg_quote(trim((string) $dir, '/'), '~') . '/~',
			array_values((array) ($config['exclude'] ?? [])),
		);
		return new self(
			Formatter::Pint,
			$file,
			$runBy,
			$config === null ? null : Pint::findStandard($config['preset'] ?? null),
			[],
			$excludes,
			[],
			$path,
			$config === null ? [basename($file) . ', which is no JSON'] : [],
		);
	}


	/**
	 * A ruleset is data: the standard it references, the files it checks, the extensions and its own exclude patterns,
	 * those of its rules, and the rest of it, which is how PHP_CodeSniffer runs, or else unread. A pattern is a regular
	 * expression in which `*` stands for anything; an absolute one is matched against the path with a slash in front, a
	 * relative one against the path.
	 */
	private static function readPhpCodeSniffer(string $root, string $path, string $file, ?string $runBy): self
	{
		$xml = @simplexml_load_file($path); // @ a ruleset that is no XML gives nothing
		if ($xml === false) {
			return new self(Formatter::PhpCodeSniffer, $file, $runBy, null, [], [], [], $path, [basename($file) . ', which is no XML']);
		}

		$toRegex = fn(string $pattern, bool $relative = false) => '~' . ($relative ? '^' : '')
			. str_replace(['~', '*'], ['\~', '.*'], trim($pattern)) . '~i';
		$standard = $excludes = $ruleExcludes = $unread = [];
		$extensions = null;
		foreach ($xml->{'exclude-pattern'} ?? [] as $pattern) {
			$excludes[] = $toRegex((string) $pattern, (string) $pattern['type'] === 'relative');
		}

		foreach ($xml->arg ?? [] as $arg) {
			[$name, $value] = [(string) $arg['name'], (string) $arg['value']];
			if ($name === 'extensions') { // `inc/php` names the tokenizer beside the extension
				$extensions = array_values(array_unique(array_map(fn(string $item) => explode('/', trim($item))[0], explode(',', $value))));
			} elseif ($name === 'ignore') {
				array_push($excludes, ...array_map($toRegex, explode(',', $value)));
			} elseif (!self::isRunningArgument($name, $value)) {
				$unread[] = $name === '' ? "<arg value=\"$value\"/>" : "<arg name=\"$name\" value=\"$value\"/>";
			}
		}

		foreach ($xml->config ?? [] as $config) {
			if ((string) $config['name'] !== 'installed_paths') {
				$unread[] = "<config name=\"{$config['name']}\" value=\"{$config['value']}\"/>";
			}
		}

		foreach ($xml->children() as $element) {
			if (!in_array(
				$element->getName(),
				['file', 'exclude-pattern', 'rule', 'arg', 'config', 'ini', 'autoload', 'description'],
				true,
			)) {
				$unread[] = "<{$element->getName()}>";
			}
		}

		foreach ($xml->rule ?? [] as $rule) {
			$ref = (string) $rule['ref'];
			$standard[] = PhpCodeSniffer::getSets()[$ref] ?? null;
			foreach ($rule->{'exclude-pattern'} ?? [] as $pattern) {
				$ruleExcludes[$ref][] = $toRegex((string) $pattern, (string) $pattern['type'] === 'relative');
			}
		}

		$standard = array_values(array_filter($standard))[0] ?? null;
		return new self(
			Formatter::PhpCodeSniffer,
			$file,
			$runBy,
			$standard === null ? null : PluginRegistry::abbreviate($standard),
			self::resolvePaths($root, $path, array_map(strval(...), iterator_to_array($xml->file, preserve_keys: false))),
			$excludes,
			$ruleExcludes,
			$path,
			$unread,
			extensions: $extensions,
		);
	}


	/** Whether the argument of a ruleset says only how PHP_CodeSniffer runs and reports, nothing of what it checks. */
	private static function isRunningArgument(string $name, string $value): bool
	{
		return $name === ''
			? (bool) preg_match('~^[psvq]+$~D', $value) // progress, sniff codes, verbosity, quiet
			: in_array(
				$name,
				['basepath', 'cache', 'no-cache', 'colors', 'no-colors', 'encoding', 'parallel', 'report', 'report-width'],
				true,
			) || str_starts_with($name, 'report-');
	}


	/**
	 * A configuration of PHP CS Fixer is PHP, read from its syntax tree without running it (`PhpCsFixerSource`): the sets
	 * its rules turn on, the directories its finder takes, `->in(__DIR__ . '/lib')`, and what it leaves out of them, a
	 * directory `->exclude('Parser')`, a path `->notPath('Fixtures')` and a name `->notName('*.phpt')`.
	 */
	private static function readPhpCsFixer(string $root, string $path, string $file, ?string $runBy): self
	{
		$source = PhpCsFixerSource::fromCode((string) @file_get_contents($path), $path); // @ file may have been deleted meanwhile
		$standard = null;
		// @PhpCsFixer is @Symfony with more rules, which the standard is the nearest to
		foreach ([...PhpCsFixer::getSets(), '@PhpCsFixer' => 'dresscode/symfony'] as $set => $preset) {
			if (in_array($set, $source->sets, true)) {
				$standard ??= PluginRegistry::abbreviate($preset);
			}
		}

		// a finder of appended files alone takes those, one of neither the directory of the configuration
		$in = $source->in ?: ($source->append === [] ? [dirname($path)] : []);
		$dirs = self::resolvePaths($root, $path, $in);
		$excludes = [];
		foreach ($dirs === ['.'] ? [''] : $dirs as $dir) {
			foreach ($source->exclude as $name) {
				$excludes[] = '~^' . preg_quote(ltrim($dir . '/' . trim($name, '/'), '/'), '~') . '/~';
			}
		}

		foreach ($source->notPath as $pattern) {
			$excludes[] = @preg_match($pattern, '') === false ? '~' . preg_quote($pattern, '~') . '~' : $pattern; // @ a text is no pattern
		}

		$names = array_map(
			fn(string $name) => @preg_match($name, '') === false // @ a glob is no pattern
				? '~^' . strtr(preg_quote($name, '~'), ['\\*' => '.*', '\\?' => '.']) . '$~'
				: $name,
			$source->notName,
		);

		return new self(
			Formatter::PhpCsFixer,
			$file,
			$runBy,
			$standard,
			$source->append === [] ? $dirs : self::resolvePaths($root, $path, [...$in, ...$source->append]),
			$excludes,
			[],
			$path,
			$source->unread,
			$names,
		);
	}


	/**
	 * The paths a configuration names relative to its own directory or absolute, as paths relative to the root; one
	 * outside the root is dropped, one missing refused as the formatter refuses it, and the root among them, which takes
	 * all, is `.` alone.
	 * @param  list<string>  $paths
	 * @return list<string>
	 * @throws ConfigurationException  for a path that does not exist
	 */
	private static function resolvePaths(string $root, string $file, array $paths): array
	{
		$root = Helpers::canonicalizePath(FileSystem::normalizePath($root));
		$resolved = [];
		foreach ($paths as $path) {
			$full = Helpers::canonicalizePath(FileSystem::resolvePath(dirname($file), $path));
			if (!file_exists($full)) {
				throw new ConfigurationException("`$file` names `$path`, which does not exist.");
			} elseif ($full === $root) {
				return ['.'];
			} elseif (str_starts_with($full, "$root/")) {
				$resolved[] = substr($full, strlen($root) + 1);
			}
		}

		sort($resolved, SORT_STRING);
		return array_values(array_unique($resolved));
	}


	/** The configuration as a reader is told of it, `pint.json` of Laravel Pint, run by `composer fix`. */
	public function format(): string
	{
		return "`$this->file` of {$this->formatter->value}" . ($this->runBy === null ? '' : ", run by `$this->runBy`");
	}


	/** Whether the formatter leaves the path out, by its path or its name. */
	public function excludes(string $path): bool
	{
		return self::matchesPath($this->excludePatterns, $path)
			|| array_any($this->nameExcludePatterns, fn(string $regex) => preg_match($regex, basename($path)) === 1);
	}


	/** Whether the formatter leaves the path out of the rule. */
	public function excludesFromRule(string $path, string $rule): bool
	{
		return self::matchesPath($this->ruleExcludePatterns[$rule] ?? [], $path);
	}


	/** @param  list<string>  $patterns */
	private static function matchesPath(array $patterns, string $path): bool
	{
		return array_any($patterns, fn(string $regex) => preg_match($regex, "/$path") === 1 || preg_match($regex, $path) === 1);
	}


	/**
	 * The rules of the configuration as the decisions of DressCode, what could not be carried over said in its warnings;
	 * a configuration of PHP CS Fixer is run to give them.
	 * @throws ConfigurationException
	 */
	public function translate(): Translation
	{
		$foreign = match ($this->formatter) {
			Formatter::PhpCsFixer => PhpCsFixer::readConfig($this->path),
			Formatter::PhpCodeSniffer => PhpCodeSniffer::readConfig($this->path),
			Formatter::Pint => Pint::readConfig($this->path),
		};
		$translation = new Translator()->translate($foreign->rules, $foreign->indent, $foreign->lineEnding, $foreign->presets);
		foreach ($foreign->warnings as $warning) {
			$translation->request($warning);
		}

		foreach ($this->unread as $call) {
			$translation->request("`$call` of `$this->file` is not carried over; what the configuration checks may differ from what {$this->formatter->value} checked.");
		}

		return $translation;
	}
}
