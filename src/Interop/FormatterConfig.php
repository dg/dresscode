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
 * The configuration of a formatter in a file. Its rules, which `translate()` reads, need the configuration of PHP CS
 * Fixer run, being PHP.
 * @internal
 */
final readonly class FormatterConfig
{
	private function __construct(
		public Formatter $formatter,
		/** relative to the root, absolute where it lies outside */
		public string $file,
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
		return new self(Formatter::fromFileName($file), $file, FileSystem::isAbsolute($file) ? $file : "$root/$file");
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
		}

		[$rules, $unread] = PhpCodeSniffer::readConfig($this->path);
		$translation = $translator->translate($rules);
		foreach ($unread as $warning) {
			$translation->warn($warning);
		}

		return $translation;
	}
}
