<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\{Risk, Violation};


/**
 * When the fix of a place of an upgrade can be made.
 * @internal
 */
enum Phase: string
{
	/** on the versions the project allows today, before its constraints are raised */
	case Bridge = 'bridge';

	/** only once the constraints are raised to the target */
	case Jump = 'jump';
}


/**
 * Where a run of a plan stopped.
 * @internal
 */
enum RunStop
{
	/** every step it was to run is done */
	case Done;

	/** before a step of Composer whose constraints are not raised */
	case Composer;

	/** after a raise the run made, as what runs after it needs what it installed loaded by a process of its own */
	case Raised;

	/** at a manual step whose target the lock still refuses */
	case Manual;

	/** after a step whose tests newly failed, which went back */
	case Tests;

	/** after a step that could not fix or write some files */
	case Unprocessed;

	/** at the end of the plan, where the targets still find what no step of it fixed */
	case Remaining;

	/** before the first step, as a run was interrupted in a step that may have rewritten some files */
	case Interrupted;
}


/**
 * How a test ended, as its JUnit report says; a test repeated under one identity ends as the worst of them.
 * @internal
 */
enum TestStatus: string
{
	case Passed = 'passed';
	case Skipped = 'skipped';
	case Failed = 'failed';

	/** it threw where it was to assert */
	case Error = 'error';
}


/**
 * What git says of the tracked files of the working tree.
 * @internal
 */
enum WorkingTree
{
	case Clean;
	case Dirty;

	/** no repository, one that ignores the project, or git refused to tell */
	case Unavailable;
}


/**
 * What the run can do about a place of an upgrade.
 * @internal
 */
enum Fixability: string
{
	/** the fix is made, having no risk or one the project accepts */
	case Automatic = 'automatic';

	/** the fix waits for the types of the code to tell it is right */
	case TypeUnknown = 'typeUnknown';

	/** the fix waits for `nameResolution: certain` to tell the name reaches the global function or constant */
	case NameUncertain = 'nameUncertain';

	/** a human: no fix exists, or it changes what the code does and the consent is theirs */
	case Manual = 'manual';


	public static function of(Violation $violation): self
	{
		return match (true) {
			!$violation->fixable, $violation->refused && $violation->risk === Risk::BehaviorChanges => self::Manual,
			$violation->refused && $violation->risk === Risk::TypeUnknown => self::TypeUnknown,
			$violation->refused && $violation->risk === Risk::NameUncertain => self::NameUncertain,
			default => self::Automatic,
		};
	}
}
