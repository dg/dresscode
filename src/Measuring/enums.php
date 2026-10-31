<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;


/**
 * How `dresscode init` measures a decision, which its domain tells.
 * @internal
 */
enum MeasuringMethod
{
	/** under each of its values, every place agreeing with one of them */
	case Values;

	/** under its one value, the only number being the files a fix would change */
	case OneSided;

	/** under a ladder of the parameter it is measured on, the smallest step few files exceed */
	case Ladder;

	/** a list the project brings, which nothing measures */
	case Policy;
}


/**
 * What the code says of a decision measured under its values.
 * @internal
 */
enum Classification
{
	/** one value holds in at least the threshold of the places, and there are enough of them */
	case Settled;

	/** few places, all of one value */
	case SettledWeakly;

	/** no value alone reaches the threshold, the values the code has together do, and the decision takes a tolerance */
	case Tolerated;

	/** the code writes it in several ways */
	case Mixed;

	/** no place of the decision */
	case Absent;
}


/**
 * Where a value `dresscode init` writes comes from.
 * @internal
 */
enum ValueOrigin
{
	/** the code, or what it does not decide */
	case Measured;

	/** the user, answering a question or with `--set` */
	case Answered;

	/** the budget, which leaves unchecked a decision whose fix would change too many files */
	case OverBudget;
}


/**
 * Why `dresscode init` chose the standard.
 * @internal
 */
enum StandardReason
{
	/** the user named it */
	case Given;

	/** Laravel Pint formats the project in it */
	case Formatter;

	/** the code departs from it in the fewest decisions, a close call decided by the formatter, then `perCs` */
	case Fewest;

	/** PER Coding Style taken over PSR-12, which it succeeds, though the code departs from PSR-12 in fewer decisions */
	case Successor;
}


/**
 * Why a file of the project is left out of the scope `dresscode init` writes.
 * @internal
 */
enum LeftOutReason: string
{
	/** its head or `linguist-generated` of `.gitattributes` says a generator wrote it */
	case Generated = 'generated';

	/** a test in the format PHPT, sections of text around the code, which is no PHP */
	case PhptTest = 'PHPT test';

	/** it lies in a directory of the data of tests, whose style is the one a test needs */
	case TestData = 'test data';

	/** git ignores it, a build or a local file */
	case Ignored = 'ignored by git';

	/** the configuration of the formatter of the project leaves it out */
	case Foreign = 'left out by your formatter';

	/** it does not parse */
	case Unparsed = 'does not parse';
}
