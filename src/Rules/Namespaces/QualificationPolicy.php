<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Decision;
use DressCode\Domains\Words;


/**
 * The forms a referenced name is written in, `bare`, `imported` and `backslashed`, and the decisions of a global
 * function between them.
 * @internal shared by the rules of the qualification
 */
final readonly class QualificationPolicy
{
	public const
		Bare = 'bare',
		Imported = 'imported',
		Backslashed = 'backslashed';


	/** A decision of a global function, which may also be written bare. */
	public static function createGlobalDecision(
		string $path,
		string $description,
		string $bare,
		string $imported,
		string $backslashed,
		string $note,
	): Decision
	{
		return new Decision($path, new Words([
			self::Bare => "bare, $bare without an import or a backslash, reached by the fallback at run time",
			self::Imported => "imported, $imported",
			self::Backslashed => "with the leading backslash, $backslashed",
		], tolerance: true), $description, [$note]);
	}


	/** A decision of a global function the compiler optimizes once it knows the function is global. */
	public static function createOptimizedDecision(
		string $path,
		string $description,
		string $bare,
		string $imported,
		string $backslashed,
		string $gain,
		string $note,
	): Decision
	{
		return new Decision($path, new Words([
			self::Imported => "imported, $imported, so that the compiler $gain",
			self::Backslashed => "with the leading backslash, $backslashed, so that the compiler $gain",
			self::Bare => "bare, $bare, forgoing the optimization",
		], tolerance: true), $description, [$note]);
	}
}
