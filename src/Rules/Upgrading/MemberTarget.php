<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\MemberKind;
use DressCode\Rules\QualifiedNames;
use DressCode\Violation;


/**
 * What replacedMembers writes instead of a member, written the way its key is: `StatusPaid` and `$explorer` are
 * members of the same class, `Other\Helpers::name` one of another, `\str_contains` a function, which only a method
 * may become, being called the same way.
 * @internal
 */
final readonly class MemberTarget
{
	public function __construct(
		/** fully qualified, without a leading backslash; null for the class of the replaced member, and for a function */
		public ?string $class,
		/** of the member, without the dollar of a property, or the function with its namespace */
		public string $name,
		/** the target is a global function */
		public bool $global = false,
	) {
	}


	/** Whether the target is the member written as it is, in its letter case alone at most, which leaves nothing to rewrite. */
	public function isWrittenAs(string $name): bool
	{
		return !$this->global && $this->class === null && $name === $this->name;
	}


	/** Whether the target is no member of the class of the replaced one: a function, or a member of another class. */
	public function leavesClass(): bool
	{
		return $this->global || $this->class !== null;
	}


	/** @throws \InvalidArgumentException  saying why the code cannot stand for the member of the key */
	public static function fromCode(string $code, MemberPattern $key): self
	{
		$isProperty = $key->kind === MemberKind::Property;
		// the parentheses of a key say a method here, with no shape of its arguments
		if (
			$key->kind === MemberKind::Constructor
			|| ($key->arguments !== null && $key->arguments->items !== [] && !$key->takesAnyArguments())
		) {
			throw new \InvalidArgumentException("The member `$key->class::$key->name` cannot be replaced by a bare name, which says nothing of a constructor or of the arguments of a call.");

		} elseif (preg_match('~^\\\\(\w+(?:\\\\\w+)*)(?:\(\))?$~D', $code, $m)) {
			return $isProperty
				? throw new \InvalidArgumentException("The property `$key->class::\$$key->name` cannot be replaced by the function `$m[1]()`.")
				: new self(null, $m[1], global: true);

		} elseif (!preg_match('~^(?:\\\\?(\w+(?:\\\\\w+)*)::)?(\$)?(\w+)(\(\))?$~D', $code, $m, PREG_UNMATCHED_AS_NULL)) {
			throw new \InvalidArgumentException('The replacement ' . Violation::formatCode($code) . " of `$key->class::$key->name` is not written as `name`, `\$name`, `Class::name`, `Class::\$name` or `\\function`.");

		} elseif (($m[2] !== null) !== $isProperty || ($isProperty && $m[4] !== null)) {
			throw new \InvalidArgumentException('The replacement ' . Violation::formatCode($code) . " of `$key->class::$key->name` is not of its kind: a property is replaced by a property, a constant or a method by a constant or a method.");
		}

		return new self($m[1] !== null && strcasecmp($m[1], $key->class) !== 0 ? $m[1] : null, $m[3]);
	}


	/** The replacement as a message names it, in backticks, for a member of the kind: `Order::StatusPaid`, `Other\Helpers::name()`, `str_contains()`. */
	public function describe(MemberKind $kind, MemberPattern $key): string
	{
		if ($this->global) {
			return "`$this->name()`";
		}

		$class = $this->class ?? QualifiedNames::stripNamespace($key->class);
		return match ($kind) {
			MemberKind::Property, MemberKind::StaticProperty => "`$class::\$$this->name`",
			MemberKind::Constant => "`$class::$this->name`",
			default => "`$class::$this->name()`",
		};
	}
}
