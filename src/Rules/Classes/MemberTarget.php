<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\MemberKind;


/**
 * What replaced-members writes instead of a member, written the way its key is: `Filled` and `$explorer` are
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
		public bool $isFunction = false,
	) {
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
			throw new \InvalidArgumentException("The member $key->class::$key->name is given a name instead, which says nothing of a constructor or of a call of some arguments.");

		} elseif (preg_match('~^\\\\(\w+(?:\\\\\w+)*)(?:\(\))?$~D', $code, $m)) {
			return $isProperty
				? throw new \InvalidArgumentException("The property $key->class::\$$key->name cannot be replaced by the function $m[1]().")
				: new self(null, $m[1], isFunction: true);

		} elseif (!preg_match('~^(?:\\\\?(\w+(?:\\\\\w+)*)::)?(\$)?(\w+)(\(\))?$~D', $code, $m, PREG_UNMATCHED_AS_NULL)) {
			throw new \InvalidArgumentException("The replacement '$code' of $key->class::$key->name is not written as name, \$name, Class::name, Class::\$name or \\function.");

		} elseif (($m[2] !== null) !== $isProperty || ($isProperty && $m[4] !== null)) {
			throw new \InvalidArgumentException("The replacement '$code' of $key->class::$key->name is not of its kind: a property is replaced by a property, a constant or a method by a constant or a method.");
		}

		return new self($m[1] !== null && strcasecmp($m[1], $key->class) !== 0 ? $m[1] : null, $m[3]);
	}


	/** The replacement as a message names it, for a member of the kind: `Form::Filled`, `Other\Helpers::name()`, `str_contains()`. */
	public function describe(MemberKind $kind, MemberPattern $key): string
	{
		if ($this->isFunction) {
			return "$this->name()";
		}

		$class = $this->class ?? substr($key->class, (int) strrpos('\\' . $key->class, '\\'));
		return match ($kind) {
			MemberKind::Property, MemberKind::StaticProperty => "$class::\$$this->name",
			MemberKind::Constant => "$class::$this->name",
			default => "$class::$this->name()",
		};
	}
}
