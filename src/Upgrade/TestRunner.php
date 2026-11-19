<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * Runs the tests of the project for a run of the plan, which a test replaces to make tests fail or crash where it wants.
 * @internal
 */
interface TestRunner
{
	/** Runs the command in the root, `{junit}` standing for its report, saying why the results are not to be trusted where not. */
	function run(string $command, string $root): TestResults;
}
