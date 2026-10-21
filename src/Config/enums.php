<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;


/** What a layer of the configuration is, as `Layer` names it. */
enum LayerKind
{
	case Configuration;
	case CommandLine;
	case Override;
	case Preset;

	/** an upgrading file of a package, or what DressCode itself knows of one */
	case Package;

	/** a name a caller asks about, outside every layer of the configuration */
	case Caller;
}


/**
 * Why a decision takes no effect or a rule does not run, by the word the output of the configuration says it.
 * @internal
 */
enum InactiveReason: string
{
	/** the requirement is `keep` */
	case Keep = 'keep';

	/** the fact waits for the names of the namespaces to be certain */
	case NameResolution = 'nameResolution';

	/** the target is older than the rule needs */
	case Php = 'php';

	/** the project does not meet a package the rule needs */
	case Package = 'package';

	/** the run has no types the rule needs */
	case Types = 'types';

	/** the run is narrowed to other decisions */
	case Narrowed = 'narrowed';

	/** a layer turned the decisions of the rule off */
	case TurnedOff = 'turnedOff';

	/** only an override turns the rule on */
	case OnlyOverride = 'onlyOverride';

	/** nothing names the decisions of the rule */
	case NotMentioned = 'notMentioned';
}


/**
 * Where the PHP version the rules target was taken from.
 * @internal
 */
enum PhpVersionSource
{
	case Configuration;
	case Composer;
	case Default;
}
