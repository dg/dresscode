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
 * compiler works with is decided by the key of the optimized ones where that key requires a form, otherwise by the
 * key of every global one.
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
		foreach (['globalClass', 'globalFunction', 'optimizedFunction', 'globalConstant', 'optimizedConstant'] as $key) {
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
	 * or constant, decided by the key of the optimized ones where the compiler works with it and that key decides.
	 * @return ?array{list<string>, string}  the forms and the decision; null where the name stays as it is
	 */
	public function findGlobal(SymbolKind $kind, bool $optimized): ?array
	{
		$decision = 'qualification.' . match ($kind) {
			SymbolKind::ClassLike => 'globalClass',
			SymbolKind::Function => $optimized && $this->isCompilerDecisive($kind) ? 'optimizedFunction' : 'globalFunction',
			SymbolKind::Constant => $optimized && $this->isCompilerDecisive($kind) ? 'optimizedConstant' : 'globalConstant',
		};
		return $this->forms[$decision] === null ? null : [$this->forms[$decision], $decision];
	}


	/** Whether the global functions or constants the compiler works with are decided apart from the others, by a key requiring a form. */
	public function isCompilerDecisive(SymbolKind $kind): bool
	{
		return match ($kind) {
			SymbolKind::ClassLike => false,
			SymbolKind::Function => $this->forms['qualification.optimizedFunction'] !== null,
			SymbolKind::Constant => $this->forms['qualification.optimizedConstant'] !== null,
		};
	}
}
