<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * The places of `multiline.items` a run spreads over lines when their line is wider than the line length: one whose
 * shape is not `keep`, which the run is not narrowed away from and which `file.lineLengthExcept` does not name. A
 * construct on a line too wide leaves the break to an outer one at such a place on its line, so a rule breaking by the
 * width asks which places are.
 */
final readonly class LineSplits
{
	/** place => the decision of its shape */
	public const Places = [
		'argument' => 'multiline.items.argument',
		'parameter' => 'multiline.items.parameter',
		'condition' => 'multiline.items.condition',
		'chain' => 'multiline.items.chain',
		'ternary' => 'multiline.items.ternary',
		'for' => 'multiline.items.for',
		'logical' => 'multiline.items.logical',
		'concatenation' => 'multiline.items.concatenation',
		'arithmetic' => 'multiline.items.arithmetic',
		'bitwise' => 'multiline.items.bitwise',
		'coalesce' => 'multiline.items.coalesce',
		'pipe' => 'multiline.items.pipe',
		'array' => 'multiline.items.array',
		'list' => 'multiline.items.list',
		'implements' => 'multiline.items.implements',
		'matchCondition' => 'multiline.items.matchCondition',
	];

	private const Except = 'file.lineLengthExcept';
	private const SpreadItem = 'multiline.spreadItem';


	public function __construct(
		/** @var array<string, true> the places spread for their width */
		private array $places = [],
		/** a call too wide whose sole argument is spread for it keeps it in its parentheses, `foo(bar(` and `))` together */
		public bool $spreadSoleArgument = false,
	) {
	}


	public static function fromValues(Values $values): self
	{
		$excepted = $values->has(self::Except) ? array_flip($values->get(self::Except)->getNames()) : [];
		$places = [];
		foreach (self::Places as $place => $path) {
			if (!isset($excepted[$place]) && $values->has($path) && $values->isSelected($path)) {
				$places[$place] = true;
			}
		}

		$sole = $values->has(self::SpreadItem) && $values->get(self::SpreadItem)->getWord() === 'sole';
		return new self($places, $sole);
	}


	/** Whether a construct at the place is spread over lines when its line is too wide. */
	public function has(string $place): bool
	{
		return isset($this->places[$place]);
	}
}
