<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use DressCode\Domains\{Count, Words};
use function count, is_array, is_bool, is_string;
use const JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE;


/**
 * What a decision takes: the values it accepts, how a value is normalized, how the values of two layers merge,
 * and a description of itself as data and as prose, which the catalogue exports and the reference and `explain`
 * read. The domains are those of `Domains\`, which a plugin declares its decisions with; it writes none of its own.
 */
abstract readonly class Domain
{
	/** @internal the item of a list standing for the list of the layer below it */
	public const Spread = '...';

	private const StateWords = [
		'forbidden' => 'never there',
		'required' => 'always there',
	];

	private const PlacementWords = [
		'sameLine' => 'on the line of what comes before',
		'nextLine' => 'on the next line',
	];

	private const AlignmentWords = [
		'forbidden' => 'never there, more whitespace collapsing to a single space',
		'spaces' => 'spaces aligning a column staying, tabs not',
		'tabs' => 'tabs aligning a column staying, spaces not',
		'optional' => 'there or not, as the author wrote it',
	];


	/** The words of a state, `forbidden`, `required` or both. */
	public static function state(string ...$words): Words
	{
		$picked = self::pickWords(self::StateWords, $words ?: ['forbidden']);
		return new Words($picked);
	}


	/** The words of where a token stands, `sameLine` and `nextLine` unless others are named. */
	public static function placement(string ...$words): Words
	{
		return new Words(self::pickWords(self::PlacementWords, $words ?: ['sameLine', 'nextLine']));
	}


	/** The words of where an operator stands at a line break, opening the line after it or ending the line before it. */
	public static function operatorPosition(): Words
	{
		return new Words([
			'lineStart' => 'one ending a line opens the next, unless a comment follows it',
			'lineEnd' => 'one opening a line ends the one before, unless a comment precedes it',
		]);
	}


	/** The words of which whitespace wider than a single space stays, aligning a column; all four unless some are named. */
	public static function alignment(string ...$words): Words
	{
		return new Words(self::pickWords(self::AlignmentWords, $words ?: array_keys(self::AlignmentWords)));
	}


	/** A count of blank lines, or a range of them. */
	public static function blankLines(): Count
	{
		return new Count;
	}


	/**
	 * The value as NEON or PHP gives it, normalized into the Value of this domain; `keep` is taken where `$keep`
	 * allows it, which a requirement does and a parameter does not.
	 * @throws ConfigurationException  naming what the domain accepts
	 */
	final public function accept(mixed $raw, string $path, bool $keep = false): Value
	{
		return $raw === 'keep' && $keep
			? Value::keep($this)
			: $this->normalize($raw, $path, $keep);
	}


	/**
	 * The value of two layers merged by the law of the domain: `keep` above wins, and a value above replaces the
	 * one below, unless the domain merges its maps or the list above holds `...` for the one below.
	 * @throws ConfigurationException
	 */
	public function merge(Value $below, Value $above, string $path = ''): Value
	{
		return $above;
	}


	/**
	 * The list above with its `...` replaced by the list below in its place; an item both give is merged in a set and
	 * refused in an order or a tolerance.
	 * @param  list<string>  $below
	 * @param  list<string>  $above
	 * @return list<string>
	 * @throws ConfigurationException
	 */
	protected static function spread(array $below, array $above, bool $set, string $path): array
	{
		$at = array_search(self::Spread, $above, true);
		if ($at === false) {
			return $above;
		}

		$list = [...array_slice($above, 0, $at), ...$below, ...array_slice($above, $at + 1)];
		$twice = array_diff_key($list, array_unique($list));
		if ($twice !== [] && !$set) {
			throw new ConfigurationException("Key `$path` gives `" . reset($twice) . '` itself and in the list `...` stands for; write it once.');
		}

		return array_values(array_unique($list));
	}


	/**
	 * The domain as data: its kind, its words or shapes with their meanings, bounds, children, whether a
	 * tolerance or a map of names is taken.
	 * @return array<string, mixed>
	 */
	abstract public function toArray(): array;


	/** The values the domain takes in prose, as the comment of an export lists them after `Also:`. */
	abstract public function describe(): string;


	/**
	 * The one value the domain takes, as a layer writes it; null for a domain of several.
	 * @internal
	 */
	public function findSoleValue(): mixed
	{
		return null;
	}


	/**
	 * @internal
	 * @throws ConfigurationException
	 */
	abstract protected function normalize(mixed $raw, string $path, bool $keep): Value;


	/**
	 * The entries of a map below with those above laid over them, a name above replacing the same one below.
	 * @param  array<Value>  $below
	 * @param  array<Value>  $above
	 * @return array<Value>
	 */
	protected static function mergeEntries(array $below, array $above, bool $caseInsensitive): array
	{
		if ($caseInsensitive) {
			$names = array_flip(array_map(self::canonicalizeName(...), array_keys($above)));
			$below = array_filter($below, fn($name) => !isset($names[self::canonicalizeName((string) $name)]), ARRAY_FILTER_USE_KEY);
		}

		return [...$below, ...$above];
	}


	/** The name of a symbol of PHP as PHP reads it, in any letter case and with or without its leading backslash. */
	protected static function canonicalizeName(string $name): string
	{
		return strtolower(ltrim($name, '\\'));
	}


	/**
	 * @param  list<string>  $expected  what the domain takes, each already in backticks
	 * @throws ConfigurationException
	 */
	protected static function refuse(mixed $raw, string $path, array $expected, bool $keep, string $why = ''): never
	{
		if ($keep) {
			$expected[] = '`keep`';
		}

		$list = count($expected) > 1
			? implode(', ', array_slice($expected, 0, -1)) . ' or ' . end($expected)
			: ($expected[0] ?? 'nothing');
		throw new ConfigurationException("Key `$path` does not take " . self::formatRaw($raw) . ($why === '' ? '' : ", $why") . "; write $list.");
	}


	/** The value as the reader wrote it, in backticks. */
	protected static function formatRaw(mixed $raw): string
	{
		return Violation::formatCode(match (true) {
			is_string($raw) => $raw,
			is_bool($raw) => $raw ? 'yes' : 'no',
			$raw === null => 'null',
			is_array($raw) && $raw === [] => '[]',
			default => (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		});
	}


	/**
	 * @param  array<string, string>  $known
	 * @param  array<string>  $words
	 * @return array<string, string>
	 */
	private static function pickWords(array $known, array $words): array
	{
		$picked = [];
		foreach ($words as $word) {
			$picked[$word] = $known[$word] ?? throw new \InvalidArgumentException("Word `$word` is none of `" . implode('`, `', array_keys($known)) . '`.');
		}

		return $picked;
	}
}
