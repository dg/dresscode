<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\Config\ComposerJson;
use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function is_array, is_string, strlen;


/**
 * The configuration of the formatter a project has, found where a project keeps it. What `dresscode migrate config`
 * takes of it besides its rules is read as data and never run: the standard it names and how the project runs it. Only
 * its rules, which `translate()` reads, need the configuration of PHP CS Fixer run, being PHP.
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
	];


	private function __construct(
		public Formatter $formatter,
		/** relative to the root, absolute where it lies outside */
		public string $file,
		/** the command of the project that runs it, `composer check`; null where none does */
		public ?string $runBy,
		/** the standard of DressCode its preset is; null for none */
		public ?string $standard,
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
		return $formatter === Formatter::Pint
			? self::readPint($path, $file, $runBy)
			: new self($formatter, $file, $runBy, null, $path);
	}


	/** Laravel Pint: the standard of its preset, `laravel` where it names none. */
	private static function readPint(string $path, string $file, ?string $runBy): self
	{
		$config = ComposerJson::read($path) ?? [];
		return new self(Formatter::Pint, $file, $runBy, self::PintPresets[$config['preset'] ?? 'laravel'] ?? null, $path);
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
			return $translator->translate(
				(array) ($config['rules'] ?? []),
				presets: $this->standard === null ? [] : ["dresscode/$this->standard"],
			);
		}

		[$rules, $unread] = PhpCodeSniffer::readConfig($this->path);
		$translation = $translator->translate($rules);
		foreach ($unread as $warning) {
			$translation->warn($warning);
		}

		return $translation;
	}
}
