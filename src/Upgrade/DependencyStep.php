<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * A step Composer makes, changing the packages of the project.
 * @internal
 */
interface DependencyStep extends Step
{
	/**
	 * The commands of Composer that make the step, as a user types them.
	 * @return list<string>
	 */
	function formatCommands(): array;
}
