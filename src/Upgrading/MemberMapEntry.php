<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrading;

use DressCode\Config\Layer;


/**
 * An entry of a map of members: its key, what the rule keeps of its value, the decision a use of it is reported
 * under, the map itself or the requirement that laid the upgrading data holding it, and the section of those data.
 * @template T
 */
final readonly class MemberMapEntry
{
	/** @internal made by `MemberMap` */
	public function __construct(
		public MemberPattern $pattern,
		/** @var T */
		public mixed $value,
		public string $decision,
		/** the section of the upgrading data of a package that laid the entry, null where the project wrote it */
		public ?Layer $origin = null,
	) {
	}
}
