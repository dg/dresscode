<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * A member, named by the class that declares it and not by the text that reaches it: `$order::STATUS_PAID`,
 * `MyOrder::STATUS_PAID` and `self::STATUS_PAID` in a child are one member. A declaration, which `findOverridden()` asks about, is named the same way.
 */
final readonly class Member
{
	public function __construct(
		public MemberKind $kind,
		public string $name,
		/** fully qualified, without a leading backslash */
		public string $declaringClass,
	) {
	}


	public function describe(): string
	{
		return $this->kind->describe($this->declaringClass, $this->name);
	}
}
