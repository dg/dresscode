<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Decision, Values};
use DressCode\Domains\Words;
use PhpSyntax\SymbolKind;


/**
 * The forms a referenced name is written in, `bare`, `imported` and `backslashed`, the decisions of the qualification
 * between them, and what those decisions say of a name of the global namespace: a global function or constant the
 * compiler works with is decided by the key of the optimized ones, every other one by the key of the global ones.
 * @internal shared by the rules of the qualification
 */
final readonly class QualificationPolicy
{
	public const
		Bare = 'bare',
		Imported = 'imported',
		Backslashed = 'backslashed';

	/** @var array<string, ?list<string>>  decision => the forms, null for `keep` */
	private array $forms;


	public function __construct(Values $values)
	{
		$forms = [];
		foreach (['class.global', 'function.global', 'function.optimized', 'constant.global', 'constant.optimized'] as $key) {
			$forms["qualification.$key"] = $values->find("qualification.$key")?->getWords();
		}

		$this->forms = $forms;
	}


	/** A decision of a name that is written imported or with the leading backslash. */
	public static function createQualifiedDecision(
		string $path,
		string $description,
		string $imported,
		string $backslashed,
		?string $note = null,
	): Decision
	{
		return new Decision($path, new Words([
			self::Imported => "imported, $imported",
			self::Backslashed => "with the leading backslash, $backslashed",
		], tolerance: true), $description, $note === null ? [] : [$note]);
	}


	/** A decision of a name of the namespace of the file, which is written bare or with the leading backslash. */
	public static function createCurrentNamespaceDecision(string $path, string $description, string $bare, string $backslashed): Decision
	{
		return new Decision($path, new Words([
			self::Bare => "bare, $bare relative to the namespace",
			self::Backslashed => "with the leading backslash, $backslashed",
		], tolerance: true), $description);
	}


	/** A decision of a global function or constant, which may also be written bare. */
	public static function createGlobalDecision(
		string $path,
		string $description,
		string $bare,
		string $imported,
		string $backslashed,
		?string $note = null,
	): Decision
	{
		return new Decision($path, new Words([
			self::Bare => "bare, $bare without an import or a backslash, reached by the fallback at run time",
			self::Imported => "imported, $imported",
			self::Backslashed => "with the leading backslash, $backslashed",
		], tolerance: true), $description, $note === null ? [] : [$note]);
	}


	/** A decision of a global function or constant the compiler works with once it knows the name is global. */
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


	/**
	 * The forms of a name of the global namespace referenced in a namespace: of a global class, or of a global function
	 * or constant, decided by the key of the optimized ones where the compiler works with it.
	 * @return ?array{list<string>, string}  the forms and the decision; null where the name stays as it is
	 */
	public function findGlobal(SymbolKind $kind, bool $optimized): ?array
	{
		$decision = 'qualification.' . match ($kind) {
			SymbolKind::ClassLike => 'class.global',
			SymbolKind::Function => $optimized ? 'function.optimized' : 'function.global',
			SymbolKind::Constant => $optimized ? 'constant.optimized' : 'constant.global',
		};
		return $this->forms[$decision] === null ? null : [$this->forms[$decision], $decision];
	}


	/** Whether a key decides some global name of the kind. */
	public function decides(SymbolKind $kind): bool
	{
		return match ($kind) {
			SymbolKind::ClassLike => $this->forms['qualification.class.global'] !== null,
			SymbolKind::Function => $this->forms['qualification.function.global'] !== null
				|| $this->forms['qualification.function.optimized'] !== null,
			SymbolKind::Constant => $this->forms['qualification.constant.global'] !== null
				|| $this->forms['qualification.constant.optimized'] !== null,
		};
	}
}
