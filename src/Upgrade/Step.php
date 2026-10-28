<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * One step of a plan, a recipe rather than its result: the topics a fix makes, the constraints Composer raises, or
 * what a human does, which the run measures anew whenever it comes to it.
 * @internal
 */
final readonly class Step
{
	public function __construct(
		public StepKind $kind,
		public string $title,
		/** @var list<string>  the topics of a fix as `--only` takes them, each made and committed apart */
		public array $only = [],
		/** @var array<string, string>  package of `require` => the constraint a step of Composer writes */
		public array $require = [],
		/** a step of Composer updates the packages too, not only `composer.json` */
		public bool $update = true,
		/** what a human is advised to run, for a manual step */
		public ?string $suggest = null,
		/** the user left it out */
		public bool $skip = false,
	) {
	}
}
