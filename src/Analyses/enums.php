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
}
