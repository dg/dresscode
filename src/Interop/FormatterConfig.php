<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\Config\{ComposerJson, Draft};
use DressCode\{ConfigurationException, Override, Profile};
use DressCode\Engine\Helpers;
use DressCode\Measuring\Scope;
use Nette\Utils\FileSystem;
use function array_key_exists, is_array, is_string, strlen;


/**
 * The configuration of the formatter a project has, found where a project keeps it. What `dresscode init` and
 * `dresscode migrate config` take of it is read as data and never run: the standard it names, the paths it checks and
 * those it leaves out, the paths a rule of it leaves out, and how the project runs it. Only its rules, which
 * `translate()` reads, need the configuration of PHP CS Fixer run, being PHP.
 * @internal
 */
final readonly class FormatterConfig
{
	/** the presets of Laravel Pint and the standard each is */
	private const PintPresets = [
		'laravel' => 'laravel',
		'psr12' => 'psr12',
		'per' => 'perCs',
		'symfony' => 'symfony',
		'empty' => null,
	];

	/** what a configuration of Laravel Pint says that import carries over, the rest of it said in the warnings */
	private const PintKeys = ['preset', 'rules', 'exclude', 'cache-file'];


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
		public array $excludes,
		/** @var array<string, list<string>>  foreign rule => regular expressions of the paths it leaves out */
		public array $ruleExcludes,
		/** absolute */
		private string $path,
	) {
	}


	/** The configuration of the formatter the project has, the one a script of composer.json runs first; null for none. */
	public static function find(string $root): ?self
	{
		$scripts = self::collectScripts($root);
		foreach ($scripts as [$name, $command]) {
			if (preg_match('~\bphpcs\b.*--standard[= ](\S+\.xml(?:\.dist)?)~', $command, $m) && is_file("$root/$m[1]")) {
				return self::read($root, $m[1], Formatter::PhpCodeSniffer, "composer $name");
			} elseif (preg_match('~\bphp-cs-fixer\b.*--config[= ](\S+\.php)~', $command, $m) && is_file("$root/$m[1]")) {
				return self::read($root, $m[1], Formatter::PhpCsFixer, "composer $name");
			}
		}

		foreach (Formatter::cases() as $formatter) {
			foreach ($formatter->getRootFiles() as $file) {
				if (is_file("$root/$file")) {
					$runBy = array_find($scripts, fn(array $script) => str_contains($script[1], $formatter->getCommand()));
					return self::read($root, $file, $formatter, $runBy === null ? null : "composer $runBy[0]");
				}
			}
		}

		return null;
	}


	/** The configuration in the file given, relative to the root or absolute, the formatter told by its extension. */
	public static function fromFile(string $root, string $file): self
	{
		$file = Helpers::canonicalizePath($file);
		$root = Helpers::canonicalizePath($root);
		$file = str_starts_with($file, "$root/") ? substr($file, strlen($root) + 1) : $file;
		return self::read($root, $file, Formatter::fromFileName($file), null);
	}


	private static function read(string $root, string $file, Formatter $formatter, ?string $runBy): self
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
		$config = ComposerJson::read($path) ?? [];
		$excludes = array_map(
			fn($dir) => '~^' . preg_quote(trim((string) $dir, '/'), '~') . '/~',
			array_values((array) ($config['exclude'] ?? [])),
		);
		return new self(
			Formatter::Pint,
			$file,
			$runBy,
			is_string($preset = $config['preset'] ?? 'laravel') ? self::PintPresets[$preset] ?? null : null,
			[],
			$excludes,
			[],
			$path,
		);
	}


	/**
	 * A ruleset is data: the standard it references, the files it checks, its own exclude patterns and those of its
	 * rules. A pattern is a regular expression in which `*` stands for anything; an absolute one is matched against the
	 * path with a slash in front, a relative one against the path.
	 */
	private static function readPhpCodeSniffer(string $root, string $path, string $file, ?string $runBy): self
	{
		$xml = @simplexml_load_file($path); // @ a ruleset that is no XML gives nothing
		if ($xml === false) {
			return new self(Formatter::PhpCodeSniffer, $file, $runBy, null, [], [], [], $path);
		}

		$toRegex = fn(\SimpleXMLElement $pattern) => '~' . ((string) $pattern['type'] === 'relative' ? '^' : '')
			. str_replace(['~', '*'], ['\~', '.*'], (string) $pattern) . '~i';
		$standard = $excludes = $ruleExcludes = [];
		foreach ($xml->{'exclude-pattern'} ?? [] as $pattern) {
			$excludes[] = $toRegex($pattern);
		}

		foreach ($xml->rule ?? [] as $rule) {
			$ref = (string) $rule['ref'];
			$standard[] = PhpCodeSniffer::getSets()[$ref] ?? null;
			foreach ($rule->{'exclude-pattern'} ?? [] as $pattern) {
				$ruleExcludes[$ref][] = $toRegex($pattern);
			}
		}

		$standard = array_values(array_filter($standard))[0] ?? null;
		return new self(
			Formatter::PhpCodeSniffer,
			$file,
			$runBy,
			$standard === null ? null : substr($standard, strlen('dresscode/')),
			self::resolvePaths($root, dirname($path), array_map(strval(...), iterator_to_array($xml->file, preserve_keys: false))),
			$excludes,
			$ruleExcludes,
			$path,
		);
	}


	/**
	 * A configuration of PHP CS Fixer is PHP, read here as text: the sets it names and the directories its finder takes
	 * and excludes, `->in(__DIR__ . '/lib')` with `->exclude('Parser')`.
	 */
	private static function readPhpCsFixer(string $root, string $path, string $file, ?string $runBy): self
	{
		$code = (string) @file_get_contents($path); // @ file may have been deleted meanwhile
		$standard = null;
		// @PhpCsFixer is @Symfony with more rules, which the standard is the nearest to
		foreach ([...PhpCsFixer::getSets(), '@PhpCsFixer' => 'dresscode/symfony'] as $set => $preset) {
			if (preg_match('~[\'"]' . preg_quote($set, '~') . '[\'"]\s*=>\s*true~', $code)) {
				$standard ??= substr($preset, strlen('dresscode/'));
			}
		}

		preg_match_all('~->in\(\s*__DIR__\s*\.\s*[\'"]/?([^\'"]*)[\'"]~', $code, $in);
		preg_match_all('~->exclude\(\s*[\'"]([^\'"]+)[\'"]~', $code, $exclude);
		$excludes = [];
		foreach ($in[1] ?: [''] as $dir) {
			foreach ($exclude[1] as $name) {
				$excludes[] = '~^' . preg_quote(ltrim(trim($dir, '/') . '/' . trim($name, '/'), '/'), '~') . '/~';
			}
		}

		return new self(
			Formatter::PhpCsFixer,
			$file,
			$runBy,
			$standard,
			self::resolvePaths($root, dirname($path), array_map(fn(string $dir) => $dir === '' ? '.' : $dir, $in[1])),
			$excludes,
			[],
			$path,
		);
	}


	/**
	 * The paths a configuration names relative to its own directory or absolute, as paths relative to the root; one
	 * outside the root or missing is dropped, and the root among them, which takes all, is `.` alone.
	 * @param  list<string>  $paths
	 * @return list<string>
	 */
	private static function resolvePaths(string $root, string $dir, array $paths): array
	{
		$root = Helpers::canonicalizePath(FileSystem::normalizePath($root));
		$resolved = [];
		foreach ($paths as $path) {
			$full = Helpers::canonicalizePath(FileSystem::resolvePath($dir, $path));
			if ($full === $root) {
				return ['.'];
			} elseif (str_starts_with($full, "$root/") && file_exists($full)) {
				$resolved[] = substr($full, strlen($root) + 1);
			}
		}

		sort($resolved, SORT_STRING);
		return array_values(array_unique($resolved));
	}


	/**
	 * The scripts of composer.json, each command of a script on its own.
	 * @return list<array{string, string}>  name, command
	 */
	private static function collectScripts(string $root): array
	{
		$scripts = [];
		foreach ((array) (ComposerJson::read("$root/composer.json")['scripts'] ?? []) as $name => $commands) {
			foreach (is_array($commands) ? $commands : [$commands] as $command) {
				if (is_string($command)) {
					$scripts[] = [(string) $name, $command];
				}
			}
		}

		return $scripts;
	}


	/** Whether the formatter leaves the path out, by a pattern of its own or of the rule given. */
	public function excludes(string $path, ?string $rule = null): bool
	{
		$patterns = $rule === null ? $this->excludes : $this->ruleExcludes[$rule] ?? [];
		return array_any($patterns, fn(string $regex) => preg_match($regex, "/$path") === 1 || preg_match($regex, $path) === 1);
	}


	/**
	 * The rules of the configuration as the decisions of DressCode, what could not be carried over said in its warnings;
	 * a configuration of PHP CS Fixer is run to give them.
	 * @throws ConfigurationException
	 */
	public function translate(): Translation
	{
		$translator = new Translator;
		if ($this->formatter === Formatter::PhpCsFixer) {
			return $translator->translate(...PhpCsFixer::readConfig($this->path));
		} elseif ($this->formatter === Formatter::Pint) {
			$config = ComposerJson::read($this->path) ?? throw new ConfigurationException("File `$this->file` is no configuration of Laravel Pint.");
			$preset = $config['preset'] ?? 'laravel';
			if (!is_string($preset) || !array_key_exists($preset, self::PintPresets)) {
				throw new ConfigurationException(
					"File `$this->file` names the preset `" . (is_string($preset) ? $preset : json_encode($preset)) . '`, which Laravel Pint does not have; it has '
					. implode(', ', array_map(fn(string $name) => "`$name`", array_keys(self::PintPresets))) . '.',
				);
			}

			$translation = $translator->translate(
				(array) ($config['rules'] ?? []),
				presets: $this->standard === null ? [] : ["dresscode/$this->standard"],
			);
			foreach (array_diff(array_keys($config), self::PintKeys) as $key) {
				$translation->warn("`$key` of `$this->file` is not carried over; the scope of the configuration may differ from the one of Laravel Pint.");
			}

			return $translation;
		}

		[$rules, $unread] = PhpCodeSniffer::readConfig($this->path);
		$translation = $translator->translate($rules);
		foreach ($unread as $warning) {
			$translation->warn($warning);
		}

		return $translation;
	}


	/**
	 * Adds the overrides that keep the decisions a rule of the formatter stands for in the files of the scope it leaves
	 * out, the project having said so.
	 * @param  \Closure(string): bool  $keepable  whether the decision of the path is kept there
	 */
	public function addOverrides(Draft $draft, Scope $scope, \Closure $keepable): void
	{
		$translator = new Translator;
		foreach (array_keys($this->ruleExcludes) as $rule) {
			$decisions = array_filter($translator->findPaths($rule), $keepable);
			$files = array_values(array_filter($scope->files, fn(string $file) => $this->excludes($file, $rule)));
			if ($decisions && $files) {
				$tree = [];
				foreach ($decisions as $path) {
					$tree = Helpers::placeValue($tree, $path, 'keep');
				}

				$draft->addOverride(
					new Override($scope->collapse($files), new Profile(decisions: $tree)),
					"where $rule is left out by your formatter",
				);
			}
		}
	}
}
