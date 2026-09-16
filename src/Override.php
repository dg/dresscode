<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * A profile for a part of the tree: a file the patterns match gets it on top of the configuration, and of the overrides
 * it matches the later one has the last word. A part of a project with a convention of its own needs a profile of its
 * own, not a rule turned off everywhere.
 */
final readonly class Override
{
	public function __construct(
		/** @var list<string>  patterns, relative to the root */
		public array $paths,
		public Profile $profile,
	) {
		if ($paths === []) {
			throw new \InvalidArgumentException('An override needs the paths it applies to.');
		} elseif ($packages = array_diff_key($profile->targets, ['php' => true])) {
			$package = array_key_first($packages);
			throw new \InvalidArgumentException('The override for `' . implode(', ', $paths) . "` targets the version of PHP alone, `$package` given; the version of a package is one the whole project is written for, so it goes into `targets` of the configuration.");
		}

		Config\ManifestFields::checkGlobs($paths, 'overrides');
	}
}
