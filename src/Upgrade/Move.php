<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * A package the update to the targets moves from one installed version to another.
 * @internal
 */
final readonly class Move
{
	public function __construct(
		public string $package,
		/** the installed version, as Composer writes it, `v3.2.10` */
		public string $from,
		/** the version the update installs */
		public string $to,
	) {
	}


	/** Whether the move crosses a major version, or a minor one below 1.0, where a package may break its API. */
	public function isMajor(): bool
	{
		[$from, $to] = [self::splitVersion($this->from), self::splitVersion($this->to)];
		return $to[0] !== $from[0] || ($to[0] === 0 && $to[1] !== $from[1]);
	}


	/**
	 * Whether the upgrading data know the version the package moves to, by the newest version they know of each library.
	 * @param  array<string, string>  $knownVersions  library => the newest version its data know
	 */
	public function isKnown(array $knownVersions): bool
	{
		[$major, $minor] = self::splitVersion($this->to);
		$known = $knownVersions[$this->package] ?? null;
		return $known !== null && version_compare($known, $major === 0 ? "0.$minor" : "$major.0", '>=');
	}


	/** @return array{int, int}  the major and the minor number of a version as Composer writes it, `v3.2.10` */
	private static function splitVersion(string $version): array
	{
		preg_match('~^v?(\d+)(?:\.(\d+))?~', $version, $m);
		return [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0)];
	}
}
