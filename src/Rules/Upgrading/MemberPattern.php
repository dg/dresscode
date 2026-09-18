<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, MemberKind, Parameter, Types};
use DressCode\{Tristate, Violation};
use PhpSyntax\Node;
use PhpSyntax\Nodes\{ArgumentListNode, NameNode};
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;


/**
 * The key of a map of members, a member written the way an upgrading guide writes it: `Class::name` is a constant
 * or a method, whichever the code accesses, `Class::NAME` without a lower-case letter a constant only,
 * `Class::name($a, true)` a method called with arguments of that shape, `Class::name()` one called without any and
 * `Class::name(...$args)` one called with any, `Class::$name` a property and `Class::__construct($a)` an instantiation;
 * the arguments are for the rule to bind, and one that rewrites the name alone takes the parentheses for a method
 * whatever they hold. A property is used through one of its two hooks, as PHP names them: `Class::$name::get` is
 * a read of it, `isset()` among them, `Class::$name::set` a write, an assignment of any kind and `unset()`.
 * A method is static or not alike, the same one being reached as `$this->name()` and `parent::name()`;
 * `Class->name()` is one that is not static, for a class whose later version has a static method of that name.
 * A bare `Class::name(...)` is refused, being a first-class callable in PHP.
 */
final readonly class MemberPattern
{
	private function __construct(
		/** fully qualified, without a leading backslash */
		public string $class,
		/** Method, Property or Constructor, the static kinds being under them; null for a constant or a method */
		public ?MemberKind $kind,
		public string $name,
		/** the shape the arguments of a call have to have; null for a key without parentheses, which takes any */
		public ?ArgumentPattern $arguments = null,
		/** a method written `Class->name()`, which is not static */
		public bool $nonStatic = false,
		/** the hook of a property the key is of, `'get'` or `'set'`; null for both */
		public ?string $hook = null,
	) {
	}


	/** @throws \InvalidArgumentException  saying what is wrong with the key */
	public static function fromKey(string $key): self
	{
		if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)(::|->)(\$)?(\w+)(?:::(get|set))?(?:\((.*)\))?$~Ds', trim($key), $m, PREG_UNMATCHED_AS_NULL)) {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . " is not written as `Class::name`, `Class::name()`, `Class->name()`, `Class::\$name`, `Class::\$name::get` or `Class::name(\$argument, ...)`.");
		}

		[, $class, $operator, $dollar, $name, $hook, $parentheses] = $m;
		$nonStatic = $operator === '->';
		if ($hook !== null && $dollar === null) {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . " names a hook, which only a property has, `Class::\$name::$hook`.");
		} elseif ($dollar !== null && $parentheses !== null) {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . ' is a property and takes no arguments.');
		} elseif ($parentheses !== null && trim($parentheses) === '...') {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . " reads as a first-class callable; a call with any arguments is written `$class$operator$name(...\$args)`.");
		} elseif ($nonStatic && ($parentheses === null || strcasecmp($name, '__construct') === 0)) {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . ' is written with `->`, which says a method that is not static, `Class->name()`.');
		}

		try {
			$arguments = $parentheses === null ? null : ArgumentPattern::parse($parentheses);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('The member ' . Violation::formatCode($key) . " cannot be read: {$e->getMessage()}", previous: $e);
		}

		return match (true) {
			$dollar !== null => new self($class, MemberKind::Property, $name, hook: $hook),
			strcasecmp($name, '__construct') === 0 => new self($class, MemberKind::Constructor, '__construct', $arguments),
			$parentheses !== null => new self($class, MemberKind::Method, $name, $arguments, $nonStatic),
			default => new self($class, null, $name),
		};
	}


	/** A method of the class, called with the arguments of the shape given, or with any. */
	public static function forMethod(string $class, string $name, ?ArgumentPattern $arguments = null): self
	{
		return new self($class, MemberKind::Method, $name, $arguments);
	}


	/**
	 * Whether the access is one of this member: the kind fits, the name agrees, a method whatever its letter case,
	 * and every class of the receiver is the class or its subtype; a constructor only where the class itself declares
	 * the one that runs, which it does for a child declaring none and for `parent::__construct()`, a child with
	 * a constructor of its own being another class. A key written `Class->name()` is not of a call with `::`, unless
	 * every class has the method and not static, `parent::name()`, and a property the class does not declare is not
	 * the one a child declares under its name. The arguments are not looked at.
	 */
	public function matches(MemberAccess $access, Types $types): bool
	{
		$isMethod = $access->kind === MemberKind::Method || $access->kind === MemberKind::StaticMethod;
		$fits = match ($this->kind) {
			null => ($isMethod && $this->canBeMethod()) || $access->kind === MemberKind::Constant,
			MemberKind::Method => $isMethod,
			MemberKind::Property => ($access->kind === MemberKind::Property || $access->kind === MemberKind::StaticProperty)
				&& (!$access->declared || $types->hasMember($this->class, MemberKind::Property, $this->name)),
			MemberKind::Constructor => $access->kind === MemberKind::Constructor,
			default => false,
		};
		if (!$fits || ($isMethod ? strcasecmp($access->name, $this->name) !== 0 : $access->name !== $this->name)) {
			return false;
		}

		foreach ($access->classes as $class) {
			if (
				($this->kind === MemberKind::Constructor ? strcasecmp($class, $this->class) !== 0 : $types->isSubtype($class, $this->class) !== Tristate::Yes)
				|| (
					$this->nonStatic
					&& $access->kind === MemberKind::StaticMethod
					&& ($types->isStaticMethod($class, $this->name) !== Tristate::No || !$types->hasMember($class, MemberKind::Method, $this->name))
				)
			) {
				return false;
			}
		}

		return true;
	}


	/**
	 * The arguments of the call in the words of the key, or null where the access is not of the key or the arguments
	 * are of no shape it has; a key naming no arguments takes any.
	 * @param  ?list<Parameter>  $parameters  of the method called, null where nothing declares it
	 */
	public function bind(MemberAccess $access, ArgumentListNode $arguments, ?array $parameters, Types $types): ?ArgumentBindings
	{
		return $this->matches($access, $types)
			? $this->getArgumentPattern()->bind($arguments, $parameters, $types)
			: null;
	}


	/**
	 * Whether the use of a property goes through the hook of the key: a read and `isset()` through get, a write and
	 * `unset()` through set, and any of them for a key naming no hook.
	 * @param  'get'|'set'|'isset'|'unset'  $use
	 */
	public function matchesHook(string $use): bool
	{
		return match ($this->hook) {
			null => true,
			'get' => $use === 'get' || $use === 'isset',
			default => $use === 'set' || $use === 'unset',
		};
	}


	/**
	 * Whether a method declared in the class overrides this member, or did before the library removed it: the key can
	 * be of a method, the name agrees and the class is a subtype of the class of the member other than that class itself.
	 */
	public function matchesMethodDeclaration(string $declaringClass, string $method, Types $types): bool
	{
		return $this->canBeMethod()
			&& strcasecmp($method, $this->name) === 0
			&& strcasecmp($declaringClass, $this->class) !== 0
			&& $types->isSubtype($declaringClass, $this->class) === Tristate::Yes;
	}


	/**
	 * Whether the key can be of a method: it is written as one, or it is a bare name with a lower-case letter, a name
	 * without one being a constant, which a method of that name in another case is not.
	 */
	private function canBeMethod(): bool
	{
		return $this->kind === MemberKind::Method || ($this->kind === null && preg_match('~[a-z]~', $this->name) === 1);
	}


	/**
	 * Whether a property declared in the class is this member, as a property an interface declares or a framework
	 * reads is declared by the class implementing it: the name agrees and the class is a subtype of the class of the
	 * member other than that class itself. A key of a hook is of the reads or the writes, not of the declaration.
	 */
	public function matchesPropertyDeclaration(string $declaringClass, string $property, Types $types): bool
	{
		return $this->kind === MemberKind::Property
			&& $this->hook === null
			&& $property === $this->name
			&& strcasecmp($declaringClass, $this->class) !== 0
			&& $types->isSubtype($declaringClass, $this->class) === Tristate::Yes;
	}


	/** Whether a call of the member is of the key whatever its arguments, so that the key replaces the method as a whole. */
	public function takesAnyArguments(): bool
	{
		return $this->arguments === null || $this->arguments->takesAnyArguments();
	}


	/** Of two keys of one member, the one whose arguments have the more specific shape comes first. */
	public function compareSpecificity(self $other): int
	{
		return $this->getArgumentPattern()->compareSpecificity($other->getArgumentPattern());
	}


	public function getArgumentPattern(): ArgumentPattern
	{
		return $this->arguments ?? ArgumentPattern::any();
	}


	/** The member as a message names it, of the kind the code reaches it by: ``Constant `Acme\Order::STATUS_PAID` ``. */
	public function describe(MemberKind $kind): string
	{
		return $kind->describe($this->class, $this->name);
	}


	/**
	 * The member as a message names it for the access the node makes, of the kind of the access, but a method for
	 * `self::name()`, `static::name()` and `parent::name()`, which are written as static calls and say nothing of the
	 * method being one.
	 */
	public function describeAccess(MemberAccess $access, Node $node): string
	{
		return $this->describe(
			$access->kind === MemberKind::StaticMethod
			&& $node instanceof StaticMethodCallNode
			&& $node->class instanceof NameNode
			&& $node->class->isSpecialClass()
				? MemberKind::Method
				: $access->kind,
		);
	}


	/** The lowercased name, which a rule looks a node up by before it asks the types. */
	public function getLookupName(): string
	{
		return strtolower($this->name);
	}
}
