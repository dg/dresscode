<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\PackageDiscovery;


/**
 * The version an upgrade moves the code to: of PHP, or of a package.
 * @internal
 */
final readonly class Target
{
	public function __construct(
		/** `php` or `vendor/name` */
		public string $package,
		public string $version,
	) {
	}


	public static function php(string $version): self
	{
		return new self(PackageDiscovery::Php, $version);
	}


	/** The target as a message says it, `php 8.4`, `nette/application 4.0`. */
	public function format(): string
	{
		return "$this->package $this->version";
	}
}
