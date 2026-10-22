<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\ConfigurationException;
use DressCode\Engine\Helpers;
use Nette\Utils\FileSystem;
use function strlen;


/**
 * The configuration of the formatter a project has, found where a project keeps it, and how the project runs it. Its
 * rules, which `translate()` reads, need the configuration of PHP CS Fixer run, being PHP.
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
		/** absolute */
		private string $path,
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
		return new self($formatter, $file, $runBy, FileSystem::isAbsolute($file) ? $file : "$root/$file");
	}


	/** The configuration as a reader is told of it, `pint.json` of Laravel Pint, run by `composer fix`. */
	public function format(): string
	{
		return "`$this->file` of {$this->formatter->value}" . ($this->runBy === null ? '' : ", run by `$this->runBy`");
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
			$translation->warn($warning);
		}

		return $translation;
	}
}
