<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurableRule;
use DressCode\ConfigurationException;
use DressCode\Override;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Profile;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationRule;
use Nette\Schema\Elements\ArrayType;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Helpers;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use PhpSyntax\SymbolKind;
use function count, in_array, is_array, is_int, is_string;
use const PHP_EOL;


/**
 * Lays the profiles that decide for a file one over another: the configuration, the overrides the file matches and the
 * command line, each above the presets it names with their parents, parents first and every preset once. A later layer
 * has the last word on a value, adds to a list, and merges the options it gives a rule with those of the layers below;
 * the rules keep the order of their first mention.
 * @internal
 */
final class PresetResolver
{
	/** the settings a preset may not make, because they are decisions of the project and not of a standard */
	private const ProjectDecisions = ['php', 'nameResolution', 'fixRisky', 'warnings'];

	/** the layer by which a certain resolution turns on the guard of its lists */
	private const GuardLayer = 'nameResolution: certain';

	/** @var array<string, string> */
	private array $warnings = [];

	/** @var array<class-string<Preset>, Profile> */
	private array $profiles = [];


	public function __construct(
		private readonly RuleRegistry $registry,
	) {
	}


	/**
	 * What the caller should tell the user about the configuration, each thing once.
	 * @return list<string>
	 */
	public function getWarnings(): array
	{
		return array_values($this->warnings);
	}


	/**
	 * The configuration as data for a file the given overrides match: what every rule ends up with, where it came from,
	 * and why a rule that does not run does not. The run, the result cache and whoever prints the configuration read this
	 * one result, so that none of them can say something the others do not.
	 * @param  string  $phpVersion  the version the code is written for, unless a profile says another
	 * @param  list<int>  $overrides  indexes of the overrides that match the file
	 * @param  ?Profile  $commandLine  laid over everything else
	 * @param  ?list<string>  $only  names or classes of the rules and presets the run is narrowed to
	 * @throws ConfigurationException
	 */
	public function resolve(
		Config $config,
		string $phpVersion,
		array $overrides = [],
		?Profile $commandLine = null,
		?array $only = null,
	): ResolvedConfig
	{
		/** @var array<class-string<Rule>, list<array{string, mixed}>> $layers */
		$layers = [];
		$explicit = $fixRisky = $warningRules = $presets = [];
		$symbols = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		$indent = $eol = $lineLength = $php = $resolution = null;
		foreach ($this->collectLayers(self::listProfiles($config, $overrides, $commandLine)) as [$source, $profile, $isPreset]) {
			try {
				if ($isPreset) {
					$presets[] = $source;
				}

				$indent = $profile->indent ?? $indent;
				$eol = $profile->eol ?? $eol;
				$lineLength = $profile->lineLength ?? $lineLength;
				$php = $profile->php ?? $php;
				// a resolution called certain rests on lists that must stay complete, so it turns on their guard below the
				// rules of the same profile, which may still turn it off
				if ($profile->nameResolution !== null) {
					$resolution = $profile->nameResolution;
					if ($resolution === 'certain') {
						$layers[NoUnlistedNamespacedDeclarationRule::class][] = [self::GuardLayer, true];
					}
				}

				foreach ([
					[SymbolKind::Function, $profile->namespaces['functions']],
					[SymbolKind::Constant, $profile->namespaces['constants']],
				] as [$kind, $names]) {
					foreach ($names as $name) {
						$symbols[$kind->name][Profile::toSymbolKey($kind, $name)] ??= [$name, $source];
					}
				}

				foreach ($profile->rules as $rule => $value) {
					$class = $this->registry->resolveRule($rule);
					$layers[$class][] = [$source, self::normalize($value)];
					if (!$isPreset) {
						$explicit[$class] = true;
					}
				}

				foreach ($profile->fixRisky as $rule) {
					$fixRisky[$this->registry->resolveRule($rule)] = true;
				}

				foreach ($profile->warnings as $rule) {
					$warningRules[$this->registry->resolveRule($rule)] = true;
				}
			} catch (ConfigurationException $e) {
				throw self::locate($e, $isPreset ? "preset $source" : $source);
			}
		}

		// a file whose resolution is not certain in the end has no lists to guard
		$guard = NoUnlistedNamespacedDeclarationRule::class;
		if ($resolution !== 'certain' && isset($layers[$guard])) {
			$layers[$guard] = array_values(array_filter($layers[$guard], fn(array $layer) => $layer[0] !== self::GuardLayer));
			if ($layers[$guard] === []) {
				unset($layers[$guard]);
			}
		}

		$phpVersion = $php ?? $phpVersion;
		if (version_compare($phpVersion, Config::MinPhpVersion, '<')) {
			$this->warnings['php'] = "The target PHP $phpVersion is older than PHP " . Config::MinPhpVersion . ', the oldest DressCode fixes code for;'
				. ' the code is checked as PHP ' . Config::MinPhpVersion . ', so a fix may write syntax the target does not have.';
			$phpVersion = Config::MinPhpVersion;
		}

		// `only` filters what the rest comes to, so it takes a rule away and never enables one
		$narrowed = $only ? $this->resolveOnly($only) : null;
		$kept = $narrowed === null ? null : array_fill_keys(array_merge(...array_column($narrowed, 1)), true);
		$active = $inactive = [];
		foreach ($layers as $class => $ruleLayers) {
			$resolved = $this->resolveRule(
				$class,
				$ruleLayers,
				$phpVersion,
				explicit: isset($explicit[$class]),
				kept: $kept === null || isset($kept[$class]),
				fixRisky: isset($fixRisky[$class]),
				warning: isset($warningRules[$class]),
			);
			$resolved->isActive() ? $active[$class] = $resolved : $inactive[$class] = $resolved;
		}

		$ofOverrides = $this->findRulesOfOverrides($config);
		foreach ($this->registry->getRules() as $name => $class) {
			if (!isset($layers[$class])) {
				$reason = isset($ofOverrides[$class]) ? 'only an override turns it on' : 'no preset or rule of the configuration mentions it';
				$inactive[$class] = new ResolvedRule($name, $class, [], [], inactive: $reason, fixRisky: isset($fixRisky[$class]));
			}
		}

		if ($overrides === []) {
			$narrowed === null
				? $this->checkFixRisky($config, $active, $ofOverrides)
				: self::checkOnly($narrowed, $active, $inactive, $ofOverrides);
		}

		// one entry per symbol, spelled the way the first layer naming it spells it
		$bySource = fn(array $entries): array => array_column($entries, 1, 0);
		return new ResolvedConfig(
			[...array_values($active), ...array_values($inactive)],
			is_int($indent) ? str_repeat(' ', $indent) : "\t",
			match ($eol) {
				'LF' => "\n",
				'CRLF' => "\r\n",
				'platform' => PHP_EOL === "\r\n" ? "\r\n" : "\n",
				default => 'majority',
			},
			$phpVersion,
			$presets,
			namespacedFunctions: $bySource($symbols[SymbolKind::Function->name]),
			namespacedConstants: $bySource($symbols[SymbolKind::Constant->name]),
			nameResolution: $resolution ?? 'uncertain',
			lineLength: $lineLength ?: null,
		);
	}


	/**
	 * Instances of the rules that run, in the order they run.
	 * @return list<Rule>
	 * @throws ConfigurationException
	 */
	public function build(ResolvedConfig $resolved): array
	{
		$rules = [];
		foreach ($resolved->getActiveRules() as $rule) {
			$rules[] = self::buildRule($rule);
		}

		return $rules;
	}


	/**
	 * The profiles that decide for a file matching the overrides, lowest first, each with the name an error or an origin
	 * is told by.
	 * @param  list<int>  $overrides
	 * @return list<array{string, Profile}>
	 */
	private static function listProfiles(Config $config, array $overrides, ?Profile $commandLine = null): array
	{
		$profiles = [['the configuration', $config]];
		foreach ($overrides as $index) {
			$profiles[] = [self::describeOverride($config->overrides[$index]), $config->overrides[$index]];
		}

		if ($commandLine !== null) {
			$profiles[] = ['the command line', $commandLine];
		}

		return $profiles;
	}


	/**
	 * The profiles as layers, lowest first: each of them above the presets it names with their parents, and a preset where
	 * it is named first.
	 * @param  list<array{string, Profile}>  $profiles  who says it and what it says
	 * @return list<array{string, Profile, bool}>  who says it, what it says, and whether that is a preset
	 * @throws ConfigurationException
	 */
	private function collectLayers(array $profiles): array
	{
		$layers = $visited = [];
		foreach ($profiles as [$source, $profile]) {
			foreach ($profile->presets as $preset) {
				try {
					$class = $this->registry->resolvePreset($preset);
				} catch (ConfigurationException $e) {
					throw self::locate($e, $source);
				}

				$this->collectPreset($class, $layers, $visited);
			}

			$layers[] = [$source, $profile, false];
		}

		return $layers;
	}


	/**
	 * @param  class-string<Preset>  $class
	 * @param  list<array{string, Profile, bool}>  $layers
	 * @param  array<class-string<Preset>, true>  $visited
	 * @throws ConfigurationException
	 */
	private function collectPreset(string $class, array &$layers, array &$visited): void
	{
		if (isset($visited[$class])) {
			return;
		}

		$visited[$class] = true;
		$name = PresetInfo::of($class)->name;
		$profile = $this->getProfile($class);
		foreach ($profile->presets as $parent) {
			try {
				$parent = $this->registry->resolvePreset($parent);
			} catch (ConfigurationException $e) {
				throw self::locate($e, "preset $name");
			}

			$this->collectPreset($parent, $layers, $visited);
		}

		$layers[] = [$name, $profile, true];
	}


	/**
	 * The profile of a preset, read once; a value it cannot hold or a decision of the project it makes is an error
	 * of the preset.
	 * @param  class-string<Preset>  $class
	 * @throws ConfigurationException
	 */
	private function getProfile(string $class): Profile
	{
		if (isset($this->profiles[$class])) {
			return $this->profiles[$class];
		}

		$name = PresetInfo::of($class)->name;
		try {
			$profile = (new $class)->getProfile();
		} catch (\InvalidArgumentException $e) {
			throw new ConfigurationException("{$e->getMessage()} (in preset $name)", previous: $e);
		}

		$defaults = new Profile;
		foreach (self::ProjectDecisions as $key) {
			if ($profile->$key !== $defaults->$key) {
				throw new ConfigurationException("Preset $name sets $key, which is a decision of the project, not of a standard.");
			}
		}

		return $this->profiles[$class] = $profile;
	}


	/** An error said in a layer the reader has to be sent to; the configuration and the command line need no pointing at. */
	private static function locate(ConfigurationException $e, string $layer): ConfigurationException
	{
		return in_array($layer, ['the configuration', 'the command line'], true)
			? $e
			: new ConfigurationException("{$e->getMessage()} (in $layer)", previous: $e);
	}


	private static function describeOverride(Override $override): string
	{
		return 'the override for ' . implode(', ', $override->paths);
	}


	/**
	 * `keep` says at every level that the rule enforces nothing, which is what `false` says in the PHP
	 * notation of a configuration, so the two meet here and the rest of the resolver knows one of them.
	 */
	private static function normalize(mixed $value): mixed
	{
		return $value === 'keep' ? false : $value;
	}


	/**
	 * The rules a run narrowed by `only` keeps, per name it was given: a rule for itself, a preset for every
	 * rule it and its parents mention.
	 * @param  list<string>  $names
	 * @return list<array{class-string<Rule>|class-string<Preset>, list<class-string<Rule>>}>  what a name names and the rules it lets in
	 * @throws ConfigurationException
	 */
	private function resolveOnly(array $names): array
	{
		$narrowed = [];
		foreach ($names as $name) {
			$class = $this->registry->resolveRuleOrPreset($name);
			if (!is_a($class, Preset::class, allow_string: true)) {
				$narrowed[] = [$class, [$class]];
				continue;
			}

			$layers = $visited = $rules = [];
			$this->collectPreset($class, $layers, $visited);
			foreach ($layers as [, $profile]) {
				foreach (array_keys($profile->rules) as $rule) {
					$rules[] = $this->registry->resolveRule($rule);
				}
			}

			$narrowed[] = [$class, $rules];
		}

		return $narrowed;
	}


	/**
	 * A name of `only` that lets in nothing that runs would make a run that checks nothing and says it is
	 * clean; a rule that runs only where an override enables it is not such a name.
	 * @param  list<array{class-string<Rule>|class-string<Preset>, list<class-string<Rule>>}>  $narrowed
	 * @param  array<class-string<Rule>, ResolvedRule>  $active
	 * @param  array<class-string<Rule>, ResolvedRule>  $inactive
	 * @param  array<class-string<Rule>, true>  $ofOverrides
	 * @throws ConfigurationException
	 */
	private static function checkOnly(array $narrowed, array $active, array $inactive, array $ofOverrides): void
	{
		foreach ($narrowed as [$class, $rules]) {
			if (array_filter($rules, fn(string $rule) => isset($active[$rule]) || isset($ofOverrides[$rule]))) {
				continue;
			} elseif (is_subclass_of($class, Preset::class)) {
				throw new ConfigurationException('Preset ' . PresetInfo::of($class)->name . ' the run is narrowed to has no rule that runs here.');
			}

			$rule = $inactive[$class];
			throw new ConfigurationException(
				"Rule $rule->name the run is narrowed to does not run: $rule->inactive."
				. (str_starts_with((string) $rule->inactive, 'it needs PHP') ? '' : " Turn it on with --rule $rule->name=on."),
			);
		}
	}


	/**
	 * A rule named in fixRisky that runs nowhere, not even where an override turns it on, makes the entry a line that
	 * does nothing, which is worth a warning.
	 * @param  array<class-string<Rule>, ResolvedRule>  $active
	 * @param  array<class-string<Rule>, true>  $ofOverrides
	 * @throws ConfigurationException
	 */
	private function checkFixRisky(Config $config, array $active, array $ofOverrides): void
	{
		foreach (self::listProfiles($config, array_keys($config->overrides)) as [$source, $profile]) {
			foreach ($profile->fixRisky as $rule) {
				try {
					$class = $this->registry->resolveRule($rule);
				} catch (ConfigurationException $e) {
					throw self::locate($e, $source);
				}

				if (!isset($active[$class]) && !isset($ofOverrides[$class])) {
					$name = RuleInfo::of($class)->name;
					$this->warnings["fixRisky $name"] = "Rule $name is named in fixRisky but runs nowhere; the entry does nothing.";
				}
			}
		}
	}


	/**
	 * The rules some override of the configuration turns on, itself or by a preset, whichever file it applies to.
	 * @return array<class-string<Rule>, true>
	 * @throws ConfigurationException
	 */
	private function findRulesOfOverrides(Config $config): array
	{
		$rules = [];
		foreach ($config->overrides as $override) {
			foreach ($this->collectLayers([[self::describeOverride($override), $override]]) as [$source, $profile, $isPreset]) {
				try {
					foreach ($profile->rules as $rule => $value) {
						if (self::normalize($value) !== false) {
							$rules[$this->registry->resolveRule($rule)] = true;
						}
					}
				} catch (ConfigurationException $e) {
					throw self::locate($e, $isPreset ? "preset $source" : $source);
				}
			}
		}

		return $rules;
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  list<array{string, mixed}>  $layers
	 * @param  bool  $explicit  whether a layer other than a preset mentions the rule
	 * @param  bool  $kept  whether `only` keeps the rule, or the run is not narrowed
	 * @param  bool  $fixRisky  whether the project accepts its fixes that may change what the code does
	 * @param  bool  $warning  whether its violations only warn
	 * @throws ConfigurationException
	 */
	private function resolveRule(
		string $class,
		array $layers,
		string $phpVersion,
		bool $explicit,
		bool $kept,
		bool $fixRisky,
		bool $warning,
	): ResolvedRule
	{
		$info = RuleInfo::of($class);
		$last = $layers[count($layers) - 1][1];
		$tooNew = $info->minPhpVersion !== null && version_compare($phpVersion, $info->minPhpVersion, '<');
		$inactive = match (true) {
			$last === false => 'turned off by ' . $layers[count($layers) - 1][0],
			$tooNew => "it needs PHP $info->minPhpVersion and the target is $phpVersion",
			!$kept => 'the run is narrowed to other rules',
			default => null,
		};
		if ($tooNew && $last !== false && $explicit) {
			$this->warnings[$info->name] = "Rule $info->name needs PHP $info->minPhpVersion, the target is $phpVersion; skipped.";
		}

		$options = [];
		if ($inactive === null) {
			[$options, $warnings] = self::validateOptions($class, $info->name, self::stack($layers, $info), self::describeSources($layers));
			foreach ($warnings as $message) {
				$this->warnings["$info->name $message"] = "Rule $info->name: $message";
			}
		}

		return new ResolvedRule(
			$info->name,
			$class,
			$options,
			$layers,
			$inactive,
			$last instanceof \Closure ? $last : null,
			$fixRisky,
			$warning,
		);
	}


	/**
	 * The layers a rule ends up with, as the schema takes them: turning the rule off drops everything said
	 * before it, so a map written after it starts from the defaults of the schema again.
	 * @param  list<array{string, mixed}>  $layers
	 * @return list<array<string, mixed>>
	 * @throws ConfigurationException
	 */
	private static function stack(array $layers, RuleInfo $info): array
	{
		$stack = [];
		foreach ($layers as [$source, $value]) {
			if ($value === false) {
				$stack = [];
			} elseif (is_array($value)) {
				$stack[] = self::markLists($value, top: true);
			} elseif (is_string($value) || is_int($value)) {
				$stack[] = [self::decisionOf($info, $source) => $value];
			} else {
				$stack[] = [];
			}
		}

		return $stack;
	}


	/**
	 * The option a bare value written for the rule fills. A rule that is more than one decision has none,
	 * and then the value has nowhere to go.
	 * @throws ConfigurationException
	 */
	private static function decisionOf(RuleInfo $info, string $source): string
	{
		return $info->decision ?? throw new ConfigurationException(
			"Rule $info->name takes no bare value, which $source gives it; write the options it has.",
		);
	}


	/**
	 * Who set the options of a rule, for an error message that has to send the reader somewhere.
	 * @param list<array{string, mixed}> $layers
	 */
	private static function describeSources(array $layers): string
	{
		$sources = [];
		foreach ($layers as [$source, $value]) {
			if (is_array($value)) {
				$sources[$source] = true;
			}
		}

		return implode(' and ', array_keys($sources));
	}


	/**
	 * A map merges with the layer below it key by key, a list replaces it whole; the marker is how every
	 * merge() of nette/schema is told the second, and without it a list of a preset and a list of the
	 * project would be appended to one another.
	 */
	private static function markLists(mixed $value, bool $top = false): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		foreach ($value as $key => $item) {
			$value[$key] = self::markLists($item);
		}

		if (!$top && array_is_list($value)) {
			$value[Helpers::PreventMerging] = true;
		}

		return $value;
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  true|string|int|array<string, mixed>|\Closure(): Rule  $value
	 */
	public static function createRule(string $class, bool|string|int|array|\Closure $value = true): Rule
	{
		$info = RuleInfo::of($class);
		$name = $info->name;
		return self::buildRule(new ResolvedRule(
			$name,
			$class,
			self::validateOptions($class, $name, self::stack([['the caller', $value]], $info))[0],
			[['the caller', $value]],
			factory: $value instanceof \Closure ? $value : null,
		));
	}


	/** @throws ConfigurationException */
	private static function buildRule(ResolvedRule $resolved): Rule
	{
		$class = $resolved->class;
		$rule = $resolved->factory === null ? new $class : ($resolved->factory)();
		if (!$rule instanceof $class) {
			throw new ConfigurationException("The factory of rule $resolved->name returned " . $rule::class . " instead of $class.");
		}

		if ($rule instanceof ConfigurableRule) {
			$rule->configure($resolved->options);
		} elseif (is_array($resolved->layers[count($resolved->layers) - 1][1] ?? null)) {
			throw new ConfigurationException("Rule $resolved->name has no options.");
		}

		return $rule;
	}


	/**
	 * The options a rule ends up with: the layers processed through its schema, so that a map merges with
	 * the layer below it key by key and a list or a scalar replaces it; and what the schema warns about them,
	 * each a sentence of its own, such as that its options decide nothing or that one of them is deprecated.
	 * @param  class-string<Rule>  $class
	 * @param  list<array<string, mixed>>  $layers
	 * @param  string  $sources  who set them, for an error message
	 * @return array{array<string, mixed>, list<string>}
	 */
	private static function validateOptions(string $class, string $name, array $layers, string $sources = ''): array
	{
		if (!is_subclass_of($class, ConfigurableRule::class)) {
			return [[], []];
		}

		$schema = $class::getOptionsSchema();
		if ($schema instanceof Structure) { // a list given replaces its default instead of extending it
			foreach ($schema->getShape() as $item) {
				if ($item instanceof ArrayType) {
					$item->mergeDefaults(false);
				}
			}
		}

		$processor = new Processor;
		try {
			$normalized = $processor->processMultiple($schema, $layers ?: [[]]);
		} catch (ValidationException $e) {
			throw new ConfigurationException(
				"Invalid options of rule $name" . ($sources === '' ? '' : " set by $sources") . ': '
				. implode(' ', $e->getMessages()),
				previous: $e,
			);
		}

		return [(array) $normalized, $processor->getWarnings()];
	}
}
