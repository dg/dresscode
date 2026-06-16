<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use Nette\Schema\Schema;


interface ConfigurableRule
{
	public static function getOptionsSchema(): Schema;

	/** @param array<string, mixed> $options  validated against the schema */
	public function configure(array $options): void;
}
