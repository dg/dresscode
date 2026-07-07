<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * The kinds of construct a run spreads over lines when their line is wider than the line length, each by the
 * decision of its shape: one whose shape is not `keep`, which the run is not narrowed away from and which
 * `file.lineLengthExcept` does not name. A construct on a line too wide leaves the break to an outer one of such
 * a kind on its line, so a rule breaking by the width asks which kinds are.
 */
final readonly class LineSplits
{
	/** kind => the decision of its shape */
	public const Kinds = [
		'call' => 'multiline.call',
		'new' => 'multiline.call',
		'attribute' => 'multiline.call',
		'signature' => 'multiline.signature',
		'condition' => 'multiline.condition',
		'chain' => 'multiline.chain',
		'ternary' => 'multiline.ternary',
		'for' => 'multiline.for',
		'binaryOperator' => 'multiline.binaryOperator',
		'array' => 'multiline.array',
		'implements' => 'multiline.implements',
	];

	private const Except = 'file.lineLengthExcept';
	private const SoleArgument = 'multiline.callSoleArgument';


	public function __construct(
		/** @var array<string, true> the kinds spread for their width */
		private array $kinds = [],
		/** a call too wide whose sole argument is spread for it keeps it in its parentheses, `foo(bar(` and `))` together */
		public bool $huggedSoleArgument = false,
	) {
	}


	public static function fromValues(Values $values): self
	{
		$excepted = $values->has(self::Except) ? array_flip($values->get(self::Except)->getNames()) : [];
		$kinds = [];
		foreach (self::Kinds as $kind => $path) {
			if (!isset($excepted[$kind]) && $values->has($path) && $values->isSelected($path)) {
				$kinds[$kind] = true;
			}
		}

		$hugged = $values->has(self::SoleArgument) && $values->get(self::SoleArgument)->getWord() === 'hugged';
		return new self($kinds, $hugged);
	}


	/** Whether a construct of the kind is spread over lines when its line is too wide. */
	public function has(string $kind): bool
	{
		return isset($this->kinds[$kind]);
	}
}
