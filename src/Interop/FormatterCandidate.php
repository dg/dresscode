<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\ConfigurationException;


/**
 * A configuration of a formatter the project may run, with the evidence that it does.
 * @internal
 */
final readonly class FormatterCandidate
{
	public function __construct(
		public Formatter $formatter,
		/** relative to the root */
		public string $file,
		/** the command of the project that runs it, `composer cs`; null where none does */
		public ?string $runBy,
		public Evidence $evidence,
	) {
	}


	/** The configuration as a reader is told of it, `pint.json` of Laravel Pint, run by `composer fix`. */
	public function format(): string
	{
		return "`$this->file` of {$this->formatter->value}" . ($this->runBy === null ? '' : ", run by `$this->runBy`");
	}


	/** @throws ConfigurationException */
	public function read(string $root): FormatterConfig
	{
		return FormatterConfig::read($root, $this->file, $this->formatter, $this->runBy);
	}
}
