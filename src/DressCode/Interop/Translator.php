<?php declare(strict_types=1);

namespace DressCode\Interop;

use function is_array, is_string;


/**
 * Translates the configuration of PHP CS Fixer or PHP_CodeSniffer into the DressCode one. The two name spaces
 * do not overlap, a fixer is snake_case and a sniff is Standard.Category.Name, so a configuration mixing both
 * translates as well.
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
	 * Rules a foreign name stands for, for a suppression comment written in the foreign form; empty when
	 * no rule covers the name.
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
