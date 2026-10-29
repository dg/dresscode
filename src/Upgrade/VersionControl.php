<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What a run of the plan asks of the version control of the project and commits into it, which a test replaces to
 * make a commit fail where it wants.
 * @internal
 */
interface VersionControl
{
	/**
	 * Whether the tracked files have changes, the files given aside.
	 * @param  list<string>  $except  relative to the root
	 */
	function readStatus(array $except = []): WorkingTree;

	/**
	 * The files of those given that are tracked or would be added, the ignored ones aside.
	 * @param  list<string>  $paths  relative to the root
	 * @return list<string>
	 * @throws GitException
	 */
	function listUnignored(array $paths): array;

	/**
	 * Commits the files with the texts given, the working tree staying as it is.
	 * @param  array<string, string>  $files  relative to the root => text
	 * @throws GitException
	 */
	function commit(array $files, string $subject, string $body): void;
}
