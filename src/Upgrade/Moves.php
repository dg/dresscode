<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What raising the constraints to the targets moves: the installed packages and their versions, or why Composer
 * could not tell.
 * @internal
 */
final readonly class Moves
{
	public function __construct(
		/** @var list<Move> */
		public array $packages = [],
		/** why Composer gave no answer, null where it gave one */
		public ?string $unknown = null,
	) {
	}


	/**
	 * The moves across a major version, where a package may break its API.
	 * @return list<Move>
	 */
	public function findMajor(): array
	{
		return array_values(array_filter($this->packages, fn(Move $move) => $move->isMajor()));
	}
}
