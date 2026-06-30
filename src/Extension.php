<?php declare(strict_types=1);

namespace DressCode;


/**
 * What a package brings to a project that names it among its extensions: rules and presets known by their names, the
 * analyses it builds, the paths it leaves out and the files it skips. How the code is written is not among them; that
 * is a preset, which the project names.
 */
interface Extension
{
	/**
	 * A configuration that sets nothing but extensions, analyses, excludePaths and skipWhen; it lies below the project,
	 * whose excluded paths add to its own, whose skipWhen skips a file too and whose analysis of the same class wins.
	 */
	public function getConfig(): Config;
}
