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
 * What a step of a plan does.
 * @internal
 */
enum StepKind: string
{
	/** a human does it, the run only says what */
	case Manual = 'manual';

	/** the topics fixed one by one */
	case Fix = 'fix';

	/** the constraints of `composer.json` raised and the packages updated */
	case Composer = 'composer';
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

	/** after the step a raise leads to, which leaves tasks for a human */
	case Tasks;

	/** after a step whose tests newly failed, which went back */
	case Tests;
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
	case UnknownTypes = 'unknownTypes';

	/** the fix waits for `nameResolution: certain` to tell the name reaches the global function or constant */
	case UncertainNames = 'uncertainNames';

	/** a human: no fix exists, or it changes what the code does and the consent is theirs */
	case Manual = 'manual';


	public static function of(Violation $violation): self
	{
		return match (true) {
			!$violation->fixable, $violation->refused && $violation->risk === Risk::BehaviorChanges => self::Manual,
			$violation->refused && $violation->risk === Risk::TypeUnknown => self::UnknownTypes,
			$violation->refused && $violation->risk === Risk::NameUncertain => self::UncertainNames,
			default => self::Automatic,
		};
	}
}
