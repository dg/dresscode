<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * What the fixes of a step did: the files each topic wrote, the files the step could not process, the manual tasks each
 * topic leaves and what the fixes tell the user.
 * @internal
 */
final readonly class StepResult
{
	public function __construct(
		/** @var array<string, array<string, string>>  topic => the files it wrote => their text */
		public array $written,
		/** @var array<string, string>  the files the step could not process => why */
		public array $unprocessed,
		/** @var array<string, list<Task>>  topic => its manual tasks */
		public array $tasks,
		/** @var list<string> */
		public array $warnings,
	) {
	}
}
