<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use function array_slice, is_array, is_string;


/**
 * Translates the configuration of PHP CS Fixer or PHP_CodeSniffer into the DressCode one. The two name spaces
 * do not overlap, a fixer is snake_case and a sniff is Standard.Category.Name, so a configuration mixing both
 * translates as well.
 * @internal
 */
final class Translator
{
	/** @var array<string, string|\Closure(array<string, mixed>, Translation): mixed>  foreign rule → rule name, or a closure building the rules from the foreign options */
	private readonly array $translations;

	/** @var array<string, string>  foreign rule set → preset */
	private readonly array $sets;

	/** @var array<string, list<string>>  foreign rule → the rules it stands for */
	private array $rules = [];

	/** @var ?array<string, list<string>>  rule → the foreign rules it covers */
	private ?array $foreignNames = null;


	/**
	 * Without tables, those of PHP CS Fixer and PHP_CodeSniffer.
	 * @param  ?array<string, string|\Closure(array<string, mixed>, Translation): mixed>  $translations
	 * @param  ?array<string, string>  $sets
	 */
	public function __construct(?array $translations = null, ?array $sets = null)
	{
		$this->translations = $translations ?? PhpCsFixer::getTranslations() + PhpCodeSniffer::getTranslations();
		$this->sets = $sets ?? PhpCsFixer::getSets() + PhpCodeSniffer::getSets();
	}


	/**
	 * @param  array<string, bool|array<string, mixed>>  $rules  foreign rule => whether it is on, or its options
	 */
	public function translate(array $rules): Translation
	{
		$translation = new Translation;
		foreach ($rules as $name => $options) {
			if ($options === false) {
				$this->translateDisabled($name, $translation);
				continue;
			}

			$options = is_array($options) ? $options : [];
			$target = $this->translations[$name] ?? null;
			if (isset($this->sets[$name])) {
				$translation->preset($this->sets[$name]);
			} elseif ($target === null) {
				$translation->warn(str_starts_with($name, '@')
					? "The rule set $name has no DressCode preset; start from dresscode/per or dresscode/psr12."
					: "No DressCode rule covers $name.");
			} elseif (is_string($target)) {
				$translation->enable($target);
				if ($options !== []) {
					$translation->warn("The options of $name were not translated; review $target in the reference.");
				}
			} else {
				$target($options, $translation);
			}
		}

		return $translation;
	}


	/**
	 * A foreign rule switched off turns off the rules it stands for where no other rule of its tool covers them;
	 * a rule another one covers too is left as it is, and so is a rule an exclusion of a single message of its
	 * sniff would narrow, and the translation says so.
	 */
	private function translateDisabled(string $name, Translation $translation): void
	{
		$rules = $this->findRules($name);
		$sniff = $name;
		while ($rules === [] && substr_count($sniff, '.') > 2) {
			$sniff = implode('.', array_slice(explode('.', $sniff), 0, -1));
			if ($narrowed = $this->findRules($sniff)) {
				$translation->warn("$name is excluded, but DressCode cannot narrow " . implode(', ', $narrowed) . ' to it; the rule stays as the rest of the configuration says.');
				return;
			}
		}

		foreach ($rules as $rule) {
			$others = array_filter(
				$this->findForeignNames($rule),
				fn(string $other) => $other !== $name && str_contains($other, '.') === str_contains($name, '.'),
			);
			if ($others) {
				$translation->warn("$name is switched off, but $rule also covers " . implode(', ', $others) . ' and is not turned off; turn it off if none of them applies.');
			} else {
				$translation->disable($rule);
			}
		}
	}


	/**
	 * Rules a foreign name stands for; empty when no rule covers it.
	 * @return list<string>
	 */
	public function findRules(string $name): array
	{
		if (isset($this->rules[$name])) {
			return $this->rules[$name];
		}

		$value = $this->translations[$name] ?? null;
		if (is_string($value)) {
			return $this->rules[$name] = [$value];
		} elseif ($value === null) {
			return $this->rules[$name] = [];
		}

		$value([], $translation = new Translation);
		return $this->rules[$name] = array_keys($translation->rules);
	}


	/**
	 * Names of other tools a rule covers, for the reference and the rule listing.
	 * @return list<string>
	 */
	public function findForeignNames(string $rule): array
	{
		if ($this->foreignNames === null) {
			$map = [];
			foreach (array_keys($this->translations) as $name) {
				foreach ($this->findRules($name) as $covered) {
					$map[$covered][] = $name;
				}
			}

			$this->foreignNames = $map;
		}

		return $this->foreignNames[$rule] ?? [];
	}
}
