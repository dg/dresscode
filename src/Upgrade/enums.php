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
