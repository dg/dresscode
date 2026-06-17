<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * A named profile: rules with their options, groups, the style it needs and what the namespaces of a framework declare.
 * The presets of its profile are the ones it builds on, laid below it parents first.
 */
interface Preset
{
	/**
	 * What a preset says is a standard, never a decision of the project: its profile sets no php, types,
	 * nameResolution, fixRisky or warnings.
	 */
	public function getProfile(): Profile;
}
