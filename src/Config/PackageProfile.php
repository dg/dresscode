<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Profile;


/**
 * What one upgrading file says to the project: the options of the sections the version the project stands on reaches.
 * @internal
 */
final readonly class PackageProfile
{
	public function __construct(
		/** the file and the package shipping it, as a message names them */
		public string $source,
		public Profile $profile,
		/** @var list<string>  the versions of the sections left out as not reached yet, ascending */
		public array $unreached = [],
	) {
	}
}
