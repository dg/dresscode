<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What a run of the plan asks of the packages of the project and does to them, which a test replaces to make Composer
 * refuse or fail where it wants.
 * @internal
 */
interface PackageManager
{
	/** The installed packages of the lock refusing the version of the target, or why it cannot be told. */
	function findBlockers(Target $target): Blockers;

	/**
	 * What raising the constraints would change of the installed packages, as Composer lists the operations.
	 * @param  array<string, string>  $constraints  package => constraint
	 * @return list<string>
	 * @throws ComposerException
	 */
	function previewRequire(array $constraints, bool $update = true, ?string $platform = null): array;

	/**
	 * Raises the constraints and updates what they move.
	 * @param  array<string, string>  $constraints  package => constraint
	 * @throws ComposerException
	 */
	function require(array $constraints, bool $update = true, ?string $platform = null): void;

	/**
	 * What updating the packages to the newest versions the constraints allow would change.
	 * @return list<string>
	 * @throws ComposerException
	 */
	function previewUpdate(): array;

	/** @throws ComposerException */
	function update(): void;

	/** @throws ComposerException */
	function install(): void;
}
