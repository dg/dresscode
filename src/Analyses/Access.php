<?php declare(strict_types=1);

namespace DressCode\Analyses;


/**
 * A member access as the types see it, decided by the receiver and not by the class that declares the member:
 * `$myLoader->getCacheKey()` is an access of MyLoader, whoever declares the method, and there is one for a member
 * no class declares any more.
 */
final readonly class Access
{
	public function __construct(
		public MemberKind $kind,
		/** as written, `__construct` for a constructor */
		public string $name,
		/**
		 * @var list<string>  the classes the receiver is an instance of, or the one named; fully qualified, without
		 * a leading backslash. Those of a union and of an intersection alike, so whoever takes the access for a member
		 * of a class asks every one of them, which refuses an intersection one class of which would do.
		 */
		public array $classes,
		/** one of the classes declares the member; false for a removed or a magic one */
		public bool $declared,
	) {
	}
}
