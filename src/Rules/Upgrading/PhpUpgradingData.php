<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use Nette\Neon\Neon;
use function is_array, is_string;


/**
 * Upgrading data of PHP, written as those of a library, `php.neon` beside the class being the ones DressCode ships: a
 * function of `forbiddenFunctions` is reported as deprecated since the version of its section, whatever the target.
 * @internal
 */
final class PhpUpgradingData
{
	/** the upgrading data of PHP DressCode ships */
	public const File = __DIR__ . '/php.neon';

	/** @var ?array<lowercase-string, list<UpgradingEntry>>  the name an entry is looked up by => the entries; null until the file is read */
	private ?array $entries = null;


	private function __construct(
		private readonly string $file,
	) {
	}


	/** The data of the file, one instance per process, which reads the file when it is first asked. */
	public static function fromFile(string $file = self::File): self
	{
		static $instances = [];
		return $instances[$file] ??= new self($file);
	}


	/**
	 * The entries by the name they are looked up by.
	 * @return array<lowercase-string, list<UpgradingEntry>>
	 */
	public function getEntries(): array
	{
		return $this->entries ??= self::read($this->file);
	}


	/** @return array<lowercase-string, list<UpgradingEntry>> */
	private static function read(string $file): array
	{
		$entries = [];
		foreach ((array) Neon::decodeFile($file) as $key => $section) {
			if (!is_string($key) || !str_starts_with($key, 'since ') || !is_array($section)) {
				continue;
			}

			$since = substr($key, 6);
			foreach (array_keys((array) ($section['forbiddenFunctions'] ?? [])) as $name) {
				$entries[strtolower((string) $name)][] = new UpgradingEntry($since);
			}
		}

		return $entries;
	}
}
