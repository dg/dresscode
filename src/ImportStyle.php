<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use PhpSyntax\SymbolKind;


/**
 * How a project spreads its imports over `use` statements, which an import a rule adds takes: each kind separate or
 * combined, and whether a group use stays. A kind with no shape is written the way the file writes it.
 */
final readonly class ImportStyle
{
	/** the name of a kind => its word in the path of its decision, `imports.statement.class` */
	public const Kinds = ['ClassLike' => 'class', 'Function' => 'function', 'Constant' => 'constant'];

	public const GroupUse = 'imports.groupUse';

	/** the decisions of a tree of the core the style is read from, which every catalogue knows */
	public const Decisions = ['imports.statement.class', 'imports.statement.function', 'imports.statement.constant', self::GroupUse];


	public function __construct(
		/** @var array<string, string>  the name of a kind => `separate` or `combined` */
		private array $shapes = [],
		/** false where the project forbids a group use, which then takes no name */
		private bool $groupsKept = true,
	) {
	}


	/** The style the values of the decisions `imports.*` give. */
	public static function fromValues(Values $values): self
	{
		$shapes = [];
		foreach (self::Kinds as $kind => $word) {
			$shape = $values->find("imports.statement.$word")?->getWord();
			if ($shape !== null) {
				$shapes[$kind] = $shape;
			}
		}

		return new self($shapes, $values->find(self::GroupUse)?->getWord() !== 'forbidden');
	}


	/** The shape the imports of the kind are written in, `separate` or `combined`; null where the file decides. */
	public function getShape(SymbolKind $kind): ?string
	{
		return $this->shapes[$kind->name] ?? null;
	}


	/** Whether a group use stays, so that a name of its namespace may be written as an item of it. */
	public function keepsGroups(): bool
	{
		return $this->groupsKept;
	}
}
