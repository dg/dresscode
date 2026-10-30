<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Domains;

use DressCode\{ConfigurationException, Domain, Value};
use Nette\Schema\{Processor, Schema, ValidationException};
use function array_key_exists, is_array, is_int, is_string;


/**
 * A map of names to values of one domain, `except`, `beforeStatement`, the maps of the libraries. It merges with
 * the map below it key by key, and an entry `keep` withdraws the entry below as a tombstone, which stays until a
 * name is looked up, so that it wins as the most particular entry. The map as a whole takes no `keep`; `{}` is
 * no entries of its own.
 */
final readonly class Map extends Domain
{
	public function __construct(
		public Domain $values,
		/** a name with `*` is a pattern */
		public bool $wildcards = true,
		/** @var ?array<string, string>  name => what it means, where the names are a set of words; null for free names */
		public ?array $words = null,
		/** the grammar the entries are read by as a whole once the layers are merged, the maps of the upgrading data */
		public ?Schema $grammar = null,
		/**
		 * the names are read in any letter case and with or without their leading backslash, as PHP reads a function
		 * or a class, so a name above replaces the one below spelled otherwise and one layer names each once
		 */
		public bool $caseInsensitive = false,
		/** @var ?\Closure(string): string  the name as the lookup reads it, for names a lookup reads by more than the case */
		public ?\Closure $canonicalize = null,
		/** a name alone in the list, `[print_r]`, is an entry without a value, which is never written null */
		public bool $bareNames = false,
	) {
		if ($words !== null && ($words === [] || $wildcards)) {
			throw new \InvalidArgumentException('The words a map takes as names must be some, and they are no wildcards.');
		}
	}


	/**
	 * The entries as the grammar reads them, an entry withdrawn written `keep`; the entries as they are without one.
	 * @param  array<string, mixed>  $entries
	 * @return array<mixed>
	 * @throws ConfigurationException  where an entry is not what the grammar takes
	 */
	public function read(array $entries, string $path): array
	{
		try {
			return $this->grammar === null ? $entries : (array) (new Processor)->process($this->grammar, $entries);
		} catch (ValidationException $e) {
			throw new ConfigurationException("Invalid entries of `$path`: " . implode(' ', $e->getMessages()), previous: $e);
		}
	}


	public function merge(Value $below, Value $above, string $path = ''): Value
	{
		return $below->content === null || $above->content === null // `keep`, the default, or `keep` withdrawing the whole map
			? $above
			: new Value($this, $this->mergeEntries($below->content, $above->content), $above->raw, $above->origin);
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
		return "a map of $names to " . $this->values->describe()
			. ($this->bareNames ? ', a name alone being an entry without one' : '')
			. ', an entry withdrawn with `keep`';
	}


	protected function normalize(mixed $raw, string $path, bool $keep): Value
	{
		if ($this->bareNames && is_array($raw)) {
			$raw = self::expandBareNames($raw, $path);
		}

		if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
			self::refuse($raw, $path, ['a map of names'], $keep);
		}

		$entries = $names = [];
		foreach ($raw as $name => $value) {
			if (is_int($name) || $name === '') {
				self::refuse($raw, $path, ['a map of names'], keep: false);
			} elseif ($this->words !== null && !isset($this->words[$name])) {
				self::refuse($name, $path, array_map(fn($key) => "`$key`", array_keys($this->words)), keep: false);
			}

			$canonical = $this->canonicalizeKey($name);
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


	/**
	 * The entries with every name standing alone in the list, `[print_r]`, as an entry without a value.
	 * @param  array<mixed>  $entries
	 * @return array<mixed>
	 * @throws ConfigurationException  where an entry is null, which the name alone says, or a name stands twice
	 */
	public static function expandBareNames(array $entries, string $path): array
	{
		$expanded = [];
		foreach ($entries as $name => $value) {
			if (is_string($name) && $value === null) {
				self::refuse(null, "$path.$name", ['a text', "`[$name]` for none"], keep: true);
			} elseif (is_int($name) && is_string($value)) {
				$name = $value;
				$value = null;
			}

			if (array_key_exists($name, $expanded)) {
				throw new ConfigurationException("Key `$path` names `$name` twice; keep one of them.");
			}

			$expanded[$name] = $value;
		}

		return $expanded;
	}


	/**
	 * The entries with every one without a value written as the name alone, `[print_r]`.
	 * @param  array<mixed>  $entries
	 * @return array<mixed>
	 */
	public static function collapseBareNames(array $entries): array
	{
		$collapsed = [];
		foreach ($entries as $name => $value) {
			if ($value === null) {
				$collapsed[] = $name;
			} else {
				$collapsed[$name] = $value;
			}
		}

		return $collapsed;
	}


	/**
	 * The entries below without those the entries above name in any of their writings, then those above.
	 * @param  array<string, Value>  $below
	 * @param  array<string, Value>  $above
	 * @return array<string, Value>
	 */
	private function mergeEntries(array $below, array $above): array
	{
		$names = array_flip(array_map(fn($name) => $this->canonicalizeKey((string) $name), array_keys($above)));
		$below = array_filter($below, fn($name) => !isset($names[$this->canonicalizeKey((string) $name)]), ARRAY_FILTER_USE_KEY);
		return [...$below, ...$above];
	}


	private function canonicalizeKey(string $name): string
	{
		return match (true) {
			$this->canonicalize !== null => ($this->canonicalize)($name),
			$this->caseInsensitive => self::canonicalizeName($name),
			default => $name,
		};
	}
}
