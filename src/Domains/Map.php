<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{ConfigurationException, Domain, Value};
use function is_array, is_int;


/**
 * A map of names to values of one domain, `except`, `beforeStatement`. It merges with the map below it key by key,
 * and an entry `keep` withdraws the entry below as a tombstone, which stays until a name is looked up, so that it
 * wins as the most particular entry. The map as a whole takes no `keep`; `{}` is no entries of its own.
 */
final readonly class Map extends Domain
{
	public function __construct(
		public Domain $values,
		/** a name with `*` is a pattern */
		public bool $wildcards = true,
		/** @var ?array<string, string>  name => what it means, where the names are a set of words; null for free names */
		public ?array $words = null,
		/**
		 * the names are read in any letter case and with or without their leading backslash, as PHP reads a function
		 * or a class, so a name above replaces the one below spelled otherwise and one layer names each once
		 */
		public bool $caseInsensitive = false,
	) {
		if ($words !== null && ($words === [] || $wildcards)) {
			throw new \InvalidArgumentException('The words a map takes as names must be some, and they are no wildcards.');
		}
	}


	public function merge(Value $below, Value $above): Value
	{
		return $below->content === null // `keep`, the default
			? $above
			: new Value($this, self::mergeEntries($below->content, $above->content, $this->caseInsensitive), $above->raw, $above->origin);
	}


	public function toArray(): array
	{
		return [
			'kind' => 'map',
			'values' => $this->values->toArray(),
			'wildcards' => $this->wildcards,
			'words' => $this->words,
			'caseInsensitive' => $this->caseInsensitive,
		];
	}


	public function describe(): string
	{
		$names = $this->words === null
			? 'names' . ($this->wildcards ? ' and patterns with `*`' : '')
			: '`' . implode('`, `', array_keys($this->words)) . '`';
		return "a map of $names to " . $this->values->describe() . ', an entry withdrawn with `keep`';
	}


	public function takesKeep(): bool
	{
		return false;
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		if ($raw === 'keep') {
			self::refuse($raw, $path, ['a map of names, an entry withdrawn by `name: keep`'], false);
		} elseif (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
			self::refuse($raw, $path, ['a map of names'], false);
		}

		$entries = $names = [];
		foreach ($raw as $name => $value) {
			if (is_int($name) || $name === '') {
				self::refuse($raw, $path, ['a map of names'], false);
			} elseif ($this->words !== null && !isset($this->words[$name])) {
				self::refuse($name, $path, array_map(fn($key) => "`$key`", array_keys($this->words)), false);
			}

			$canonical = $this->caseInsensitive ? self::canonicalizeName($name) : $name;
			if (isset($names[$canonical])) {
				throw new ConfigurationException("Key `$path` names `{$names[$canonical]}` and `$name`, which are one name; keep one of them.");
			}

			$names[$canonical] = $name;
			$entries[$name] = $value === 'keep'
				? Value::keep($this->values)
				: $this->values->accept($value, "$path.$name");
		}

		return new Value($this, $entries, $raw);
	}
}
