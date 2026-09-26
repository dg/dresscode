<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax;


/**
 * How the code of a project is written: the unit of indentation, the line ending, the width of a tab and the
 * widest line. PhpSyntax measures and writes whitespace with the first three, which `toPhpSyntax()` hands over;
 * the widest line is for the rules that break or report long lines.
 */
final readonly class Style
{
	private PhpSyntax\Style $phpSyntax;


	public function __construct(
		public string $indent = "\t",
		public string $eol = "\n",
		public int $tabWidth = 4,
		/** the widest line as the reader sees it, a tab counting to its next stop; null for none */
		public ?int $lineLength = null,
	) {
		$this->phpSyntax = new PhpSyntax\Style($indent, $eol, $tabWidth);
	}


	/** Returns the prevailing line ending of the code; `"\n"` when there is none or the counts are equal. */
	public static function detectEol(string $code): string
	{
		return PhpSyntax\Style::detectEol($code);
	}


	public function withEol(string $eol): self
	{
		return new self($this->indent, $eol, $this->tabWidth, $this->lineLength);
	}


	/** Returns the indentation repeated for the level. */
	public function indent(int $level): string
	{
		return str_repeat($this->indent, $level);
	}


	/** The style PhpSyntax measures and writes whitespace with. */
	public function toPhpSyntax(): PhpSyntax\Style
	{
		return $this->phpSyntax;
	}
}
