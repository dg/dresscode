<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * The step raising the constraints of `composer.json` and updating the packages they move, or widening them alone, or
 * raising the platform of PHP alone.
 * @internal
 */
final readonly class ComposerStep implements DependencyStep
{
	public function __construct(
		public string $title,
		/** @var array<string, string>  package of `require` => the constraint written */
		public array $require,
		/** the packages updated too, not only `composer.json` */
		public bool $update = true,
		/** the version `config.platform.php` is raised to beside the constraint of PHP, where the project sets it lower */
		public ?string $platform = null,
		public bool $skip = false,
	) {
	}


	public function formatCommands(): array
	{
		$commands = $this->platform === null ? [] : ["composer config platform.php $this->platform"];
		if ($this->require !== []) {
			$arguments = Composer::buildRequire($this->require, $this->update);
			$commands[] = 'composer '
				. implode(' ', array_map(fn(string $argument) => str_contains($argument, ':') ? "\"$argument\"" : $argument, $arguments));
		} elseif ($this->update) { // the platform alone, which the lock is to say it was resolved for
			$commands[] = 'composer update --lock';
		}

		return $commands;
	}
}
