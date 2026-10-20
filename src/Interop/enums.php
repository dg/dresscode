<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;


/**
 * A formatter a project may already have, by the name it is known by.
 * @internal
 */
enum Formatter: string
{
	case PhpCodeSniffer = 'PHP_CodeSniffer';
	case PhpCsFixer = 'PHP CS Fixer';


	/** The formatter whose configuration the file is, told by its extension. */
	public static function fromFileName(string $file): self
	{
		return preg_match('~\.xml(\.dist)?$~Di', $file) ? self::PhpCodeSniffer : self::PhpCsFixer;
	}


	/**
	 * The files of its configuration a project keeps in its root, the first one found winning.
	 * @return list<string>
	 */
	public function getRootFiles(): array
	{
		return match ($this) {
			self::PhpCodeSniffer => ['phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml', '.phpcs.xml.dist'],
			self::PhpCsFixer => ['.php-cs-fixer.dist.php', '.php-cs-fixer.php'],
		};
	}


	/** The command a script of `composer.json` runs it by. */
	public function getCommand(): string
	{
		return match ($this) {
			self::PhpCodeSniffer => 'phpcs',
			self::PhpCsFixer => 'php-cs-fixer',
		};
	}


	/** Whether reading its configuration runs code, which is PHP of the project. */
	public function runsCode(): bool
	{
		return $this === self::PhpCsFixer;
	}
}
