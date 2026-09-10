<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Access, MemberKind, Types};


/**
 * The key of a map of members, a member written the way an upgrading guide writes it: `Class::name` is a constant
 * or a method, whichever the code accesses, `Class::NAME` without a lower-case letter a constant only,
 * `Class::name()` a method, `Class::$name` a property, `Class::name($a, true)` a method called with arguments of that
 * shape and `Class::__construct(...)` an instantiation. Staticness is not written, the same member being reached as
 * `$this->name()` and `parent::name()`.
 */
final readonly class MemberPattern
{
	public function __construct(
		/** fully qualified, without a leading backslash */
		public string $class,
		/** Method, Property or Constructor, the static kinds being under them; null for a constant or a method */
		public ?MemberKind $kind,
		public string $name,
		/** what stands between the parentheses; null for any arguments, which empty parentheses say too */
		public ?string $arguments = null,
	) {
	}


	/** @throws \InvalidArgumentException  saying what is wrong with the key */
	public static function fromKey(string $key): self
	{
		if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)::(\$)?(\w+)(?:\((.*)\))?$~Ds', trim($key), $m, PREG_UNMATCHED_AS_NULL)) {
			throw new \InvalidArgumentException("The member '$key' is not written as Class::name, Class::name(), Class::\$name or Class::name(\$argument, ...).");
		}

		[, $class, $dollar, $name, $parentheses] = $m;
		if ($dollar !== null && $parentheses !== null) {
			throw new \InvalidArgumentException("The member '$key' is a property and takes no arguments.");
		}

		$arguments = $parentheses === null || trim($parentheses) === '' ? null : trim($parentheses);
		return match (true) {
			$dollar !== null => new self($class, MemberKind::Property, $name),
			strcasecmp($name, '__construct') === 0 => new self($class, MemberKind::Constructor, '__construct', $arguments),
			$parentheses !== null => new self($class, MemberKind::Method, $name, $arguments),
			default => new self($class, null, $name),
		};
	}


	/**
	 * Whether the access is one of this member: the kind fits, the name agrees, a method whatever its letter case,
	 * and every class of the receiver is the class or its subtype; an instantiation only of the class itself, a child
	 * being another class to create, and a property the class does not declare is not the one a child declares
	 * under its name. The arguments are not looked at.
	 */
	public function matches(Access $access, Types $types): bool
	{
		$isMethod = $access->kind === MemberKind::Method || $access->kind === MemberKind::StaticMethod;
		$fits = match ($this->kind) {
			// a name without a lower-case letter is a constant, which a method of that name in another case is not
			null => ($isMethod && preg_match('~[a-z]~', $this->name)) || $access->kind === MemberKind::Constant,
			MemberKind::Method => $isMethod,
			MemberKind::Property => ($access->kind === MemberKind::Property || $access->kind === MemberKind::StaticProperty)
				&& (!$access->declared || $types->hasProperty($this->class, $this->name)),
			MemberKind::Constructor => $access->kind === MemberKind::Constructor,
			default => false,
		};
		if (!$fits || ($isMethod ? strcasecmp($access->name, $this->name) !== 0 : $access->name !== $this->name)) {
			return false;
		}

		foreach ($access->classes as $class) {
			if ($this->kind === MemberKind::Constructor ? strcasecmp($class, $this->class) !== 0 : !$types->isSubtype($class, $this->class)) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Whether a method declared in the class overrides this member, or did before the library removed it: the name
	 * agrees and the class is a subtype of the class of the member other than that class itself.
	 */
	public function matchesDeclaration(string $declaringClass, string $method, Types $types): bool
	{
		return ($this->kind === null || $this->kind === MemberKind::Method)
			&& strcasecmp($method, $this->name) === 0
			&& strcasecmp($declaringClass, $this->class) !== 0
			&& $types->isSubtype($declaringClass, $this->class);
	}


	/** The lowercased name, which a rule looks a node up by before it asks the types. */
	public function getLookupName(): string
	{
		return strtolower($this->name);
	}
}
