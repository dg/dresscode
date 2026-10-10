<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use PhpSyntax\{Indentation, Token};


/**
 * A line as the indentation plan places it.
 */
final readonly class Placement
{
	public function __construct(
		/** the token opening the line */
		public Token $token,
		/** the indentation the line is given */
		public string $indentation,
		/** the indentation a comment above it is given */
		public string $commentIndentation,
		/** what the line is, `a statement` */
		public string $subject,
		/** the opener of the line its level counts from */
		public ?Token $follows,
		/** the decision that places it */
		public string $decision,
	) {
	}


	/** Whether the line has the indentation it is given, a comment above it included. */
	public function isInPlace(): bool
	{
		return Indentation::matches($this->token, $this->indentation, $this->commentIndentation);
	}
}
