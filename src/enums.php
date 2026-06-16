<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * Stage of a pass: structural changes come first, formatting second, the final cleanup of comments, doc comments
 * and the whitespace of lines last.
 */
enum Stage
{
	case Structure;
	case Formatting;
	case Cleanup;
}


enum Severity
{
	case Error;
	case Warning;
}


/**
 * What a project gets from a rule beyond the looks of the code, which is the concern of a standard: the group a rule
 * belongs to, if any, so that a project can ask for all of a kind at once. A rule with none is one a standard
 * chooses or the project names.
 */
enum Group: string
{
	/** what is in the code for nothing */
	case Cleanup = 'cleanup';

	/** the construct the target version of PHP has, where an older one says the same */
	case Modernization = 'modernization';

	/** types written where PHP reads them */
	case Types = 'types';

	/** what the target version deprecated or dropped */
	case Deprecations = 'deprecations';

	/** what is most likely a mistake */
	case Correctness = 'correctness';

	/** calls in the form PHP optimizes while compiling */
	case OptimizedCalls = 'optimized-calls';
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

	/** one or more spaces, as a standard saying "at least one space" means it */
	case AtLeastSingle;

	/** anything but nothing, tabs included */
	case AtLeastSingleOrTabs;
}


/**
 * Where a gap rule puts the second of two tokens: on the line of the first, or on the next one.
 */
enum Line
{
	case Same;
	case Next;
}
