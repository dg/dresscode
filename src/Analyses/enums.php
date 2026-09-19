<?php declare(strict_types=1);

namespace DressCode\Analyses;


/** What a member is, by the syntax that reaches it. */
enum MemberKind
{
	case Constant;
	case Method;
	case StaticMethod;
	case Property;
	case StaticProperty;
	case Constructor;


	/** A member of the kind as a message names it: `Constant Nette\Forms\Form::FILLED`. */
	public function describe(string $class, string $name): string
	{
		return match ($this) {
			self::Constant => "Constant $class::$name",
			self::Method => "Method $class::$name()",
			self::StaticMethod => "Static method $class::$name()",
			self::Property => "Property $class::\$$name",
			self::StaticProperty => "Static property $class::\$$name",
			self::Constructor => "Constructor $class::$name()",
		};
	}
}
