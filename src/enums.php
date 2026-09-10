<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * Stage of a pass: structural changes come first, formatting second, the finishing of comments, doc comments
 * and the whitespace of lines last.
 */
enum Stage
{
	case Structure;
	case Formatting;
	case Finishing;
}


/**
 * The answer to a question the code may not settle.
 */
enum Tristate
{
	case Yes;
	case No;
	case Maybe;
}


/**
 * Why the fix of an occurrence may change what the code does: what would decide that it does not.
 */
enum Risk
{
	/** the type of a value, which neither the declarations in sight nor the types tell */
	case TypeUnknown;

	/** whether an unqualified name reaches a function or a constant of the namespace, which `nameResolution: certain` tells */
	case NameUncertain;

	/** a human: changing what the code does is what the fix is for, or it depends on code the run does not see */
	case BehaviorChanges;
}


enum Severity
{
	case Error;
	case Warning;
}


/**
 * What a project gets from a rule beyond the looks of the code, so that it can ask for all of a kind at once.
 */
enum RuleGroup: string
{
	/** what is in the code for nothing */
	case Cleanup = 'cleanup';

	/** the construct the target version of PHP has, where an older one says the same */
	case Modernization = 'modernization';

	/** the types of the code, declared and in doc comments */
	case Types = 'types';

	/** what the target version of PHP or a library the project stands on deprecated or dropped */
	case Deprecations = 'deprecations';

	/** what is most likely a mistake */
	case Correctness = 'correctness';

	/** calls in the form PHP optimizes while compiling */
	case OptimizedCalls = 'optimizedCalls';
}


/**
 * What a gap rule asks for between two tokens sharing a line.
 */
enum Space
{
	case None;
	case Single;

	/** a single space, or whitespace holding a tab, which aligns columns */
	case SingleOrTabs;

	/** one or more spaces, no tab */
	case AtLeastOne;

	case AtLeastOneOrTabs;
}


/**
 * Which line a gap rule asks the second of two tokens to stand on.
 */
enum Line
{
	case Same;
	case Next;
}
