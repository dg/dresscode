<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use DressCode\{Config, RuleInfo};
use DressCode\Config\{Catalogue, ConfigResolver, CorePlugin, PluginRegistry};
use function count, is_array;


/**
 * Translates the configuration of PHP CS Fixer or PHP_CodeSniffer into the DressCode one. The two name spaces
 * do not overlap, a fixer is snake_case and a sniff is Standard.Category.Name, so a configuration mixing both
 * translates as well.
 * @internal
 */
final class Translator
{
	/** @var array<string, array<string, mixed>|\Closure(array<string, mixed>, Translation): mixed>  foreign rule => the decisions it stands for, or a closure setting them from the foreign options */
	private readonly array $translations;

	/** @var array<string, string>  foreign rule set => preset */
	private readonly array $sets;

	/** @var array<string, list<string>>  foreign rule => the decisions it stands for */
	private array $paths = [];

	/** @var ?array<string, list<string>>  decision => the foreign rules standing for it */
	private ?array $foreignNames = null;

	private ?Catalogue $catalogue = null;


	/**
	 * Without tables, those of PHP CS Fixer and PHP_CodeSniffer.
	 * @param  ?array<string, array<string, mixed>|\Closure(array<string, mixed>, Translation): mixed>  $translations
	 * @param  ?array<string, string>  $sets
	 */
	public function __construct(?array $translations = null, ?array $sets = null)
	{
		$this->translations = $translations ?? PhpCsFixer::getTranslations() + PhpCodeSniffer::getTranslations();
		$this->sets = $sets ?? PhpCsFixer::getSets() + PhpCodeSniffer::getSets();
	}


	/**
	 * @param  array<string, bool|array<string, mixed>>  $rules  foreign rule => whether it is on, or its options
	 * @param  string  $indent  of the configuration object of PHP CS Fixer
	 * @param  string  $lineEnding  of the configuration object of PHP CS Fixer
	 */
	public function translate(array $rules, string $indent = '    ', string $lineEnding = "\n"): Translation
	{
		$translation = new Translation;
		$translation->indent = $indent;
		$translation->lineEnding = $lineEnding;
		foreach ($rules as $name => $options) {
			if ($options === false) {
				$this->translateDisabled($name, $translation, $rules);
				continue;
			}

			$options = is_array($options) ? $options : [];
			$target = $this->translations[$name] ?? null;
			if (isset($this->sets[$name])) {
				$translation->addPreset($this->sets[$name]);
			} elseif ($target === null) {
				$translation->warn($this->explainUncovered($name));
			} elseif (is_array($target)) {
				$translation->setAll($target);
				if ($options !== []) {
					$translation->warn("The options of `$name` were not translated; set them by hand, `dresscode explain " . array_key_first($target) . '` describes them.');
				}
			} else {
				$target($options, $translation);
			}
		}

		$this->fillCommaPlaces($translation, isset($rules['trailing_comma_in_multiline']) && $rules['trailing_comma_in_multiline'] !== false);
		$this->leaveOutTyped($translation);
		return $translation;
	}


	/**
	 * Every place of `multiline.trailingComma` stands in the translation, starting from what a preset of the
	 * translation gives it, or `keep` where none decides it, not from the defaults of the rule. A
	 * `trailing_comma_in_multiline` of PHP CS Fixer replaces what its set says of the comma of a list spread over lines,
	 * so a place it does not name keeps of the preset only the removal on one line, and without it a list the set
	 * requires the comma of stays required whatever `no_trailing_comma_in_singleline` says.
	 */
	private function fillCommaPlaces(Translation $translation, bool $multilineReplaced): void
	{
		$places = ['array', 'argument', 'parameter', 'matchArm', 'closureUse', 'import', 'list'];
		if (!array_any($places, fn(string $place) => isset($translation->decisions["multiline.trailingComma.$place"]))) {
			return;
		}

		$preset = $translation->presets === []
			? []
			: new ConfigResolver(new PluginRegistry($this))
				->resolve(new Config(use: $translation->presets), Config::DefaultPhpVersion)
				->decisions;
		foreach ($places as $place) {
			$path = "multiline.trailingComma.$place";
			$value = ($preset[$path] ?? null)?->value;
			$given = $value === null || $value->isKept() ? 'keep' : $value->getWord();
			$strict = $given === 'required' || $given === 'forbidden';
			$translation->replace($path, match (true) {
				!isset($translation->decisions[$path]) => $multilineReplaced && $strict ? 'optional' : $given,
				$translation->decisions[$path] === 'optional' && !$multilineReplaced && $strict => $given,
				default => $translation->decisions[$path],
			});
		}
	}


	/**
	 * A decision of a rule that needs the types of the code is left out and said so: the foreign configuration does not
	 * tell whether the project has PHPStan for `typeAnalysis`, without which the configuration setting it is refused.
	 */
	private function leaveOutTyped(Translation $translation): void
	{
		foreach ($translation->decisions as $path => $value) {
			$rules = $this->getCatalogue()->getRulesOf($path);
			if ($value !== 'keep' && $rules !== [] && array_all($rules, fn(string $rule) => RuleInfo::of($rule)->typesRequired)) {
				$translation->remove($path)->warn("`$path` needs the types of the code, so it is left out; set it together with `typeAnalysis: phpstan` where the project has PHPStan.");
			}
		}
	}


	/**
	 * A foreign rule switched off makes the requirements it stands for `keep` where no other rule of its tool that the
	 * configuration may run stands for them; a requirement another one stands for too is left as it is, and so is one an
	 * exclusion of a single message of its sniff would narrow, and the translation says so. A sniff and a message of it
	 * standing for the same requirement count as one.
	 * @param  array<string, bool|array<string, mixed>>  $rules
	 */
	private function translateDisabled(string $name, Translation $translation, array $rules): void
	{
		$paths = $this->findPaths($name);
		$sniff = $name;
		while ($paths === [] && substr_count($sniff, '.') > 2) {
			$sniff = implode('.', array_slice(explode('.', $sniff), 0, -1));
			if ($narrowed = $this->findPaths($sniff)) {
				$translation->warn("`$name` is excluded, but `" . implode('`, `', $narrowed) . '` cannot leave out that check alone, so '
					. (count($narrowed) > 1 ? 'they stay' : 'it stays') . ' as the rest of the configuration sets it.');
				return;
			}
		}

		$staying = $others = [];
		foreach ($paths as $path) {
			if ($this->getCatalogue()->find($path)?->isRequirement() !== true) {
				continue;
			}

			$covering = array_filter(
				$this->findForeignNames([$path]),
				fn(string $other) => $other !== $name
					&& !str_starts_with($name, "$other.")
					&& !str_starts_with($other, "$name.")
					&& str_contains($other, '.') === str_contains($name, '.')
					&& $this->canRun($other, $rules),
			);
			if ($covering) {
				$staying[] = $path;
				$others = [...$others, ...$covering];
			} else {
				$translation->keep($path);
			}
		}

		if ($staying) {
			$translation->warn("`$name` is turned off, but `" . implode('`, `', $staying) . '` also ' . (count($staying) > 1 ? 'stand' : 'stands')
				. ' for `' . implode('`, `', array_unique($others)) . '`, so ' . (count($staying) > 1 ? 'they stay' : 'it stays') . '; set ' . (count($staying) > 1 ? 'them' : 'it') . ' to `keep` if none of them applies.');
		}
	}


	/**
	 * Whether the configuration may run a foreign rule: one it names is on unless turned off, and one it does not name
	 * may come from a set or a standard of its tool, whose members the translation does not know.
	 * @param  array<string, bool|array<string, mixed>>  $rules
	 */
	private function canRun(string $name, array $rules): bool
	{
		if (isset($rules[$name])) {
			return $rules[$name] !== false;
		}

		$fixer = !str_contains($name, '.');
		return array_any(
			array_keys($rules),
			fn(string $rule) => $rules[$rule] !== false && ($fixer ? str_starts_with($rule, '@') : (bool) preg_match('~^[A-Z]\w*$~', $rule)),
		);
	}


	/** Why no rule stands for a foreign name: a rule set or a standard has no preset, a message of a sniff is not the sniff. */
	private function explainUncovered(string $name): string
	{
		if (str_starts_with($name, '@')) {
			$start = str_starts_with($name, '@PhpCsFixer') ? 'the preset `symfony`, the nearest one' : 'the preset `perCs` or `psr12`';
			return "The rule set `$name` has no DressCode preset; start from $start.";

		} elseif (preg_match('~^[A-Z]\w*$~', $name)) {
			return "The standard `$name` has no DressCode preset; start from the preset `perCs` or `psr12`.";

		} elseif (substr_count($name, '.') === 3 && $this->findPaths($sniff = substr($name, 0, (int) strrpos($name, '.')))) {
			return "`$name` is one message of `$sniff`, which DressCode cannot enable alone.";
		}

		return "No DressCode rule covers `$name`.";
	}


	/**
	 * The decisions a foreign name stands for; empty when none does.
	 * @return list<string>
	 */
	public function findPaths(string $name): array
	{
		if (isset($this->paths[$name])) {
			return $this->paths[$name];
		}

		$value = $this->translations[$name] ?? null;
		if (is_array($value)) {
			return $this->paths[$name] = array_keys($value);
		} elseif ($value === null) {
			return $this->paths[$name] = [];
		}

		$value([], $translation = new Translation);
		return $this->paths[$name] = $translation->getPaths();
	}


	/**
	 * Names of other tools standing for any of the decisions, for the reference and the listing of the rules.
	 * @param  list<string>  $paths
	 * @return list<string>
	 */
	public function findForeignNames(array $paths): array
	{
		if ($this->foreignNames === null) {
			$map = [];
			foreach (array_keys($this->translations) as $name) {
				foreach ($this->findPaths($name) as $path) {
					$map[$path][] = $name;
				}
			}

			$this->foreignNames = $map;
		}

		$names = array_flip(array_merge(...array_map(fn(string $path) => $this->foreignNames[$path] ?? [], $paths)));
		return array_values(array_filter(array_keys($this->translations), fn(string $name) => isset($names[$name])));
	}


	private function getCatalogue(): Catalogue
	{
		$core = (new CorePlugin)->getManifest();
		return $this->catalogue ??= new Catalogue($core->rules, coreDecisions: $core->decisions);
	}
}
