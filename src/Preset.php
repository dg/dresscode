<?php declare(strict_types=1);

namespace DressCode;


/**
 * A named profile: a set of rules with their options, the style it needs and what the namespaces of a framework declare.
 * The presets of its profile are the ones it builds on, laid below it parents first.
 */
interface Preset
{
	/**
	 * What a preset says is a standard, never a decision of the project: its profile sets no php, nameResolution,
	 * fixRisky or warnings.
	 */
	public function getProfile(): Profile;
}
