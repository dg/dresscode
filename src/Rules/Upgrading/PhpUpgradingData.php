<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{Config, Risk};
use Nette\Neon\Neon;
use function is_array, is_string;


/**
 * Upgrading data of PHP, written as those of a library, `php.neon` beside the class being the ones DressCode ships: a
 * function of `forbiddenFunctions` is reported as deprecated since the version of its section, whatever the target,
 * and an entry of `replacedCalls` writes its code instead of the call or, as `removed`, takes the call away, from the
 * version `from` says, the version of its section otherwise.
 * @internal
 */
final class PhpUpgradingData
{
	/** the upgrading data of PHP DressCode ships */
	public const File = __DIR__ . '/php.neon';

	/** @var ?array<lowercase-string, list<UpgradingEntry>>  the name an entry is looked up by => the entries; null until the file is read */
	private ?array $entries = null;

	/** @var array<lowercase-string, list<array{lowercase-string, UpgradingEntry}>>  the name of a method => its entries, each with the name it is looked up by */
	private array $methods = [];


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
		return $this->entries ?? $this->load();
	}


	/**
	 * The entries of a method of the name, each with the name it is looked up by.
	 * @return list<array{lowercase-string, UpgradingEntry}>
	 */
	public function getMethodEntries(string $method): array
	{
		$this->getEntries();
		return $this->methods[strtolower($method)] ?? [];
	}


	/**
	 * Reads the file, the entries and those of methods by their names.
	 * @return array<lowercase-string, list<UpgradingEntry>>
	 */
	private function load(): array
	{
		$this->entries = self::read($this->file);
		foreach ($this->entries as $name => $list) {
			foreach ($list as $entry) {
				if ($entry->pattern instanceof MemberPattern) {
					$this->methods[strtolower($entry->pattern->name)][] = [$name, $entry];
				}
			}
		}

		return $this->entries;
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
				$entries[strtolower((string) $name)][] = new UpgradingEntry(FunctionPattern::fromKey((string) $name), UpgradingOperation::Report, $since, Config::MinPhpVersion);
			}

			foreach ((array) ($section['replacedCalls'] ?? []) as $call => $value) {
				$pattern = str_contains((string) $call, '::') ? MemberPattern::fromKey((string) $call) : FunctionPattern::fromKey((string) $call);
				$entry = match (true) {
					$value === 'removed' => new UpgradingEntry($pattern, UpgradingOperation::Remove, $since, $since),
					is_array($value) => new UpgradingEntry(
						$pattern,
						UpgradingOperation::Replace,
						$since,
						(string) ($value['from'] ?? $since),
						(string) $value['write'],
						Risk::tryFrom((string) ($value['risk'] ?? '')),
						isset($value['because']) ? (string) $value['because'] : null,
					),
					default => new UpgradingEntry($pattern, UpgradingOperation::Replace, $since, $since, (string) $value),
				};
				if (
					$entry->operation === UpgradingOperation::Replace
					&& ($pattern instanceof MemberPattern || !preg_match('~^\w+\(~', (string) $entry->write))
				) {
					throw new \LogicException("The upgrading data of PHP write `$entry->write` for `$call`, but only a call of a function is written as one of another.");
				}

				$entries[$entry->getLookupName()][] = $entry;
			}
		}

		return $entries;
	}
}
