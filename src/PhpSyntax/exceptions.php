<?php declare(strict_types=1);

namespace PhpSyntax;


/**
 * The source code is not valid PHP.
 */
final class ParseException extends \Exception
{
	public function __construct(
		string $message,
		public readonly ?int $originalLine = null,
		public readonly ?int $originalOffset = null,
	) {
		parent::__construct($message);
	}
}
