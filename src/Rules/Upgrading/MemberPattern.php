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
 * `Class::name($a, true)` a method called with arguments of that shape, `Class::name()` one called without any and
 * `Class::name(...$args)` one called with any, `Class::$name` a property and `Class::__construct($a)` an instantiation;
 * the arguments are for the rule to bind, and one that rewrites the name alone takes the parentheses for a method
 * whatever they hold.
 * A method is static or not alike, the same one being reached as `$this->name()` and `parent::name()`;
 * `Class->name()` is one that is not static, for a class whose later version has a static method of that name.
 * A bare `Class::name(...)` is refused, being a first-class callable in PHP.
 */
final readonly class MemberPattern
{
	public function __construct(
		/** fully qualified, without a leading backslash */
		public string $class,
		/** Method, Property or Constructor, the static kinds being under them; null for a constant or a method */
		public ?MemberKind $kind,
		public string $name,
		/** the shape the arguments of a call have to have; null for a key without parentheses, which takes any */
		public ?ArgumentPattern $arguments = null,
		/** a method written Class->name(), which is not static */
		public bool $instance = false,
	) {
	}


	/** @throws \InvalidArgumentException  saying what is wrong with the key */
	public static function fromKey(string $key): self
	{
		if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)(::|->)(\$)?(\w+)(?:\((.*)\))?$~Ds', trim($key), $m, PREG_UNMATCHED_AS_NULL)) {
			throw new \InvalidArgumentException("The member '$key' is not written as Class::name, Class::name(), Class->name(), Class::\$name or Class::name(\$argument, ...).");
		}

		[, $class, $operator, $dollar, $name, $parentheses] = $m;
		$instance = $operator === '->';
		if ($dollar !== null && $parentheses !== null) {
			throw new \InvalidArgumentException("The member '$key' is a property and takes no arguments.");
		} elseif ($parentheses !== null && trim($parentheses) === '...') {
			throw new \InvalidArgumentException("The member '$key' reads as a first-class callable; a call with any arguments is written $class$operator$name(...\$args).");
		} elseif ($instance && ($parentheses === null || strcasecmp($name, '__construct') === 0)) {
			throw new \InvalidArgumentException("The member '$key' is written with ->, which says a method that is not static, Class->name().");
		}

		try {
			$arguments = $parentheses === null ? null : ArgumentPattern::parse($parentheses);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException("The member '$key' cannot be read: {$e->getMessage()}", previous: $e);
		}

		return match (true) {
			$dollar !== null => new self($class, MemberKind::Property, $name),
			strcasecmp($name, '__construct') === 0 => new self($class, MemberKind::Constructor, '__construct', $arguments),
			$parentheses !== null => new self($class, MemberKind::Method, $name, $arguments, $instance),
			default => new self($class, null, $name),
		};
	}


	/**
	 * Whether the access is one of this member: the kind fits, the name agrees, a method whatever its letter case,
	 * and every class of the receiver is the class or its subtype; a constructor only where the class itself declares
	 * the one that runs, which it does for a child declaring none and for parent::__construct(), a child with
	 * a constructor of its own being another class. A key written `Class->name()` is not of a call with `::`, unless
	 * every class has the method and not static, `parent::name()`, and a property the class does not declare is not
	 * the one a child declares under its name. The arguments are not looked at.
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
			if (
				($this->kind === MemberKind::Constructor ? strcasecmp($class, $this->class) !== 0 : !$types->isSubtype($class, $this->class))
				|| ($this->instance && $access->kind === MemberKind::StaticMethod && $types->isStaticMethod($class, $this->name) !== false)
			) {
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


	/** Whether a call of the member is of the key whatever its arguments, so that the key replaces the method as a whole. */
	public function takesAnyArguments(): bool
	{
		return $this->arguments === null || $this->arguments->takesAny();
	}


	/** The lowercased name, which a rule looks a node up by before it asks the types. */
	public function getLookupName(): string
	{
		return strtolower($this->name);
	}
}
