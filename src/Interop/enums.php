<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use function strlen;


/**
 * A formatter a project may already have, by the name it is known by.
 * @internal
 */
enum Formatter: string
{
	case PhpCodeSniffer = 'PHP_CodeSniffer';
	case PhpCsFixer = 'PHP CS Fixer';
	case Pint = 'Laravel Pint';


	/** The formatter whose configuration the file is, told by its extension. */
	public static function fromFileName(string $file): self
	{
		return match (true) {
			(bool) preg_match('~\.xml(\.dist)?$~Di', $file) => self::PhpCodeSniffer,
			(bool) preg_match('~\.json$~Di', $file) => self::Pint,
			default => self::PhpCsFixer,
		};
	}


	/** The formatter the word of a command runs, `vendor/bin/phpcs`; null for none. */
	public static function fromCommand(string $word): ?self
	{
		$name = basename(strtr($word, '\\', '/'));
		foreach (['.phar', '.bat'] as $suffix) {
			$name = str_ends_with($name, $suffix) ? substr($name, 0, -strlen($suffix)) : $name;
		}

		return match ($name) {
			'phpcs', 'phpcbf' => self::PhpCodeSniffer,
			'php-cs-fixer' => self::PhpCsFixer,
			'pint' => self::Pint,
			default => null,
		};
	}


	/**
	 * The files of its configuration a project keeps in its root, in the order the formatter looks for them, a local
	 * one before the one distributed.
	 * @return list<string>
	 */
	public function getRootFiles(): array
	{
		return match ($this) {
			self::PhpCodeSniffer => ['.phpcs.xml', 'phpcs.xml', '.phpcs.xml.dist', 'phpcs.xml.dist'],
			self::PhpCsFixer => ['.php-cs-fixer.php', '.php-cs-fixer.dist.php'],
			self::Pint => ['pint.json'],
		};
	}


	/** The option of its command naming the configuration. */
	public function getConfigOption(): string
	{
		return $this === self::PhpCodeSniffer ? '--standard' : '--config';
	}


	/**
	 * The packages it is installed by.
	 * @return list<string>
	 */
	public function getPackages(): array
	{
		return match ($this) {
			self::PhpCodeSniffer => ['squizlabs/php_codesniffer'],
			self::PhpCsFixer => ['friendsofphp/php-cs-fixer', 'php-cs-fixer/shim'],
			self::Pint => ['laravel/pint'],
		};
	}


	/** Whether reading its configuration runs code, which is PHP of the project. */
	public function runsCode(): bool
	{
		return $this === self::PhpCsFixer;
	}
}


/**
 * How sure it is that the project runs a configuration of a formatter, the stronger the higher.
 * @internal
 */
enum Evidence: int
{
	/** the file lies where the formatter finds it, nothing else says the project runs it */
	case File = 0;

	/** the project requires a package of the formatter */
	case Package = 1;

	/** a script of composer.json runs the formatter with it */
	case Script = 2;
}
