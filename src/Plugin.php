<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * What a package brings to a project that names it among its plugins: rules and presets known by their names, the
 * analyses it builds, the paths it leaves out and the files it skips. How the code is written is not among them;
 * that is a preset, which the project names.
 */
interface Plugin
{
	/**
	 * What the plugin brings; it lies below the project, whose excluded paths add to its own, whose skipWhen skips a file
	 * too and whose analysis of the same class wins.
	 */
	function getManifest(): PluginManifest;
}
