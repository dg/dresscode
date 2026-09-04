<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * The member an expression reaches, decided by the class that declares it and not by the text: `$order::STATUS_PAID`,
 * `MyOrder::STATUS_PAID` and `self::STATUS_PAID` in a child are one callee.
 */
final readonly class Callee
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
		return match ($this->kind) {
			MemberKind::Constant => "Constant $this->declaringClass::$this->name",
			MemberKind::Method => "Method $this->declaringClass::$this->name()",
			MemberKind::StaticMethod => "Static method $this->declaringClass::$this->name()",
			MemberKind::Property => "Property $this->declaringClass::\$$this->name",
			MemberKind::StaticProperty => "Static property $this->declaringClass::\$$this->name",
		};
	}
}
