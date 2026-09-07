<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;


/**
 * What the declarations of the project, its packages and PHP say of a class and its members, asked by their names: one
 * object per PHPStan, so that its answers hold for every pass of every file that PHPStan reads. Each question is that of
 * the same name of `Types`, which describes it and asks it here.
 * @internal
 */
final readonly class Declarations
{
	public function __construct(
		private PhpStan $phpstan,
	) {
	}


	public function findClassName(string $name): ?string
	{
		return $this->phpstan->findClassName($name);
	}
}
