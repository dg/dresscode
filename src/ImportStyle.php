<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\SymbolKind;


/**
 * How a project puts the names of its imports into `use` statements, which an import a rule adds takes: for each
 * kind the shapes that pass, `separated`, `combined` and `grouped`, the first of them written where a statement is in
 * none. A kind with no shapes is written the way the file writes it.
 */
final readonly class ImportStyle
{
	/** the name of a kind => its word in the path of its decision, `imports.grouping.class` */
	public const Kinds = ['ClassLike' => 'class', 'Function' => 'function', 'Constant' => 'constant'];

	/** the decisions of a tree of the core the style is read from, which every catalogue knows */
	public const Decisions = ['imports.grouping.class', 'imports.grouping.function', 'imports.grouping.constant'];


	public function __construct(
		/** @var array<string, list<string>>  the name of a kind => the shapes that pass, the one written first */
		private array $shapes = [],
	) {
	}


	/** The style the values of the decisions `imports.grouping.*` give. */
	public static function fromValues(Values $values): self
	{
		$shapes = [];
		foreach (self::Kinds as $kind => $word) {
			$words = $values->find("imports.grouping.$word")?->getWords();
			if ($words !== null) {
				$shapes[$kind] = $words;
			}
		}

		return new self($shapes);
	}


	/**
	 * The shape an import of the kind takes outside a group, `separated` or `combined`: the first of the two the kind
	 * names, `separated` for a kind only grouped; null where the file decides.
	 */
	public function getShape(SymbolKind $kind): ?string
	{
		$shapes = $this->shapes[$kind->name] ?? null;
		return $shapes === null ? null : array_find($shapes, fn(string $shape) => $shape !== 'grouped') ?? 'separated';
	}


	/** Whether a group use of the kind stays, so that a name of its namespace may be written as an item of it. */
	public function keepsGroups(SymbolKind $kind): bool
	{
		$shapes = $this->shapes[$kind->name] ?? null;
		return $shapes === null || in_array('grouped', $shapes, true);
	}
}
