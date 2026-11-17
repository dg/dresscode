<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use function count;


/**
 * The versions a section of an upgrading file is of, one for each minor series that got its entries on its own:
 * `3.0.26 3.1.2` holds in the series 3.0 from 3.0.26, in 3.1 from 3.1.2 and in every later series from its start,
 * while a series between two of them or below the first never got the entries. A series named by its start, as 3.1 in
 * `3.0.25 3.1`, had them before the version of the series below it came out.
 * @internal
 */
final readonly class Since
{
	private const VersionPattern = '~^\d+(?:\.\d+)*$~D';


	private function __construct(
		/** @var non-empty-list<string>  ascending, each of another series */
		public array $versions,
	) {
	}


	/**
	 * Reads the versions as a section writes them, separated by a space.
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $versions): self
	{
		$list = explode(' ', $versions);
		foreach ($list as $i => $version) {
			if (!preg_match(self::VersionPattern, $version)) {
				throw new \InvalidArgumentException("`$version` is not a version; a section is of a version, or of one for each series, as `since 3.0.26 3.1.2`.");
			} elseif ($i > 0 && version_compare(self::getSeries($list[$i - 1]), self::getSeries($version), '>=')) {
				throw new \InvalidArgumentException("The versions `$versions` must be each of another series, the oldest first.");
			}
		}

		return new self($list);
	}


	/** Whether the entries hold for the version: from the version of its series, from the start of a series above all of them. */
	public function holdsFor(string $version): bool
	{
		$series = self::getSeries($version);
		foreach ($this->versions as $since) {
			if (self::getSeries($since) === $series) {
				return version_compare($version, $since, '>=');
			}
		}

		return version_compare($series, self::getSeries($this->getNewest()), '>');
	}


	/** The version the line of the given one has the entries from, the newest not above it; the oldest for any version. */
	public function findVersionFor(?string $version): string
	{
		$found = $this->getOldest();
		foreach ($this->versions as $since) {
			if ($version !== null && version_compare($since, $version, '<=')) {
				$found = $since;
			}
		}

		return $found;
	}


	/** The version that brings the entries to code written for the given one, the oldest above it; the oldest for any version. */
	public function findVersionAfter(?string $version): string
	{
		foreach ($this->versions as $since) {
			if ($version === null || version_compare($since, $version, '>')) {
				return $since;
			}
		}

		return $this->getNewest();
	}


	public function getOldest(): string
	{
		return $this->versions[0];
	}


	public function getNewest(): string
	{
		return $this->versions[count($this->versions) - 1];
	}


	/** The minor series of the version, `3.1` of `3.1.2` and `4.0` of `4`. */
	private static function getSeries(string $version): string
	{
		$parts = explode('.', $version);
		return $parts[0] . '.' . ($parts[1] ?? '0');
	}
}
