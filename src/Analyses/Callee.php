<?php declare(strict_types=1);

namespace DressCode\Analyses;


/**
 * The member an expression reaches, decided by the class that declares it and not by the text: `$form::FILLED`,
 * `MyForm::FILLED` and `self::FILLED` in a child are one callee.
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
		return $this->kind->describe($this->declaringClass, $this->name);
	}
}
