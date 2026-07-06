<?php declare(strict_types=1);

namespace DressCode;


/**
 * Stage of a pass: structural changes come first, formatting second, the final cleanup of whitespace last.
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
