<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

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
