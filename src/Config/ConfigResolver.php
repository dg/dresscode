<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Override, Plugin, Preset, PresetInfo, Profile, Rule, RuleGroup, RuleInfo};
use DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule;
use PhpSyntax\SymbolKind;
use function count, in_array, is_int, is_string, strlen;
use const PHP_EOL;


/**
 * Lays the profiles that decide for a file one over another, each above the presets it names, and resolves every
 * rule once into a ResolvedConfig.
 * @internal
 */
final class ConfigResolver
{
	/** the settings a preset may not make, because they are decisions of the project and not of a standard */
	private const ProjectDecisions = ['targets', 'nameResolution', 'fixRisky', 'warnOnly'];

	/** the layer by which a certain resolution turns on the guard of its lists */
	private const GuardLayer = 'nameResolution: certain';

	/** @var array<string, string> */
	private array $warnings = [];

	/** @var array<class-string<Preset>, Profile> */
	private array $profiles = [];


	public function __construct(
		private readonly RuleRegistry $registry,
		/** the packages the project stands on, which decide whether a rule requiring one runs */
		private readonly ProjectPackages $project = new ProjectPackages,
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
	 * and why a rule that does not run does not.
	 * @param  string  $phpTarget  the versions the code is written for as a Composer constraint, unless a profile says another
	 * @param  list<int>  $overrides  indexes of the overrides that match the file
	 * @param  ?Profile  $commandLine  laid over everything else
	 * @param  ?list<string>  $only  names or classes of the rules and presets the run is narrowed to
	 * @throws ConfigurationException
	 */
	public function resolve(
		Config $config,
		string $phpTarget,
		array $overrides = [],
		?Profile $commandLine = null,
		?array $only = null,
	): ResolvedConfig
	{
		/** @var array<class-string<Rule>, list<array{string, mixed}>> $layers */
		$layers = [];
		$explicit = $fixRisky = $warningRules = $presets = $groups = [];
		$symbols = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		$indent = $eol = $lineLength = $php = $resolution = null;
		foreach ($this->collectLayers(self::listProfiles($config, $overrides, $commandLine)) as [$source, $profile, $isPreset]) {
			try {
				if ($isPreset) {
					$presets[] = $source;
				}

				$indent = $profile->indent ?? $indent;
				$eol = $profile->lineEnding ?? $eol;
				$lineLength = $profile->lineLength ?? $lineLength;
				$php = $profile->targets['php'] ?? $php;
				// a resolution called certain rests on lists that must stay complete, so it turns on their guard below the
				// rules of the same profile, which may still turn it off
				if ($profile->nameResolution !== null) {
					$resolution = $profile->nameResolution;
					if ($resolution === 'certain') {
						$layers[NoUnlistedNamespacedDeclarationsRule::class][] = [self::GuardLayer, true];
					}
				}

				foreach ([
					[SymbolKind::Function, $profile->namespaces['functions']],
					[SymbolKind::Constant, $profile->namespaces['constants']],
				] as [$kind, $names]) {
					foreach ($names as $name) {
						$symbols[$kind->name][self::toSymbolKey($kind, $name)] ??= [$name, $source];
					}
				}

				// a group lies under the rules of its own profile and names no rule itself, so a rule it turns on
				// is one nobody asked for by name and is left out in silence where it cannot run
				foreach ($profile->groups as $group) {
					$groups[$group->value] = true;
					foreach ($this->findRulesOfGroup($group) as $class) {
						$layers[$class][] = ["group $group->value", true];
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

				foreach ($profile->warnOnly as $rule) {
					$warningRules[$this->registry->resolveRule($rule)] = true;
				}

			} catch (ConfigurationException $e) {
				throw self::locate($e, $isPreset ? "preset $source" : $source);
			}
		}

		if ($resolution !== 'certain') {
			$layers = self::removeGuardLayer($layers);
		}

		[$phpTarget, $phpVersion] = $this->resolveTargetPhp($php ?? $phpTarget);

		// `only` filters what the rest comes to, so it takes a rule away and never enables one
		$narrowed = $only ? $this->resolveOnly($only) : null;
		$kept = $narrowed === null ? null : array_fill_keys(array_merge(...array_column($narrowed, 2)), true);
		$active = $inactive = [];
		foreach ($layers as $class => $ruleLayers) {
			$resolved = $this->resolveRule(
				$class,
				$ruleLayers,
				$phpTarget,
				types: $config->types !== null,
				explicit: isset($explicit[$class]),
				kept: $kept === null || isset($kept[$class]),
				fixRisky: isset($fixRisky[$class]),
				warnOnly: isset($warningRules[$class]),
			);
			$resolved->isActive() ? $active[$class] = $resolved : $inactive[$class] = $resolved;
		}

		$ofOverrides = $this->findRulesOfOverrides($config);
		foreach ($this->registry->rules as $name => $class) {
			if (!isset($layers[$class])) {
				[$reason, $word] = isset($ofOverrides[$class])
					? ['only an override turns it on', 'onlyOverride']
					: ['no preset or rule of the configuration mentions it', 'notMentioned'];
				$inactive[$class] = new ResolvedRule($name, $class, [], [], inactive: $reason, fixRisky: isset($fixRisky[$class]), inactiveReason: $word);
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
			self::resolveLineEnding($eol),
			$phpVersion,
			$presets,
			array_keys($groups),
			namespacedFunctions: $bySource($symbols[SymbolKind::Function->name]),
			namespacedConstants: $bySource($symbols[SymbolKind::Constant->name]),
			nameResolution: $resolution ?? 'uncertain',
			lineLength: $lineLength ?: null,
			types: $config->types,
			plugins: array_map(fn(string|Plugin $plugin) => is_string($plugin) ? $plugin : $plugin::class, $config->plugins),
		);
	}


	/**
	 * The key two spellings of one symbol share, the way PHP reads them: a function in any letter case, a constant
	 * in any letter case of its namespace but not of its own name.
	 * @internal
	 */
	public static function toSymbolKey(SymbolKind $kind, string $name): string
	{
		$name = ltrim($name, '\\');
		$pos = (int) strrpos($name, '\\');
		return $kind === SymbolKind::Constant
			? strtolower(substr($name, 0, $pos)) . substr($name, $pos)
			: strtolower($name);
	}


	/**
	 * The layers without the one by which a certain resolution turned on the guard of its lists, for a file whose
	 * resolution is not certain in the end and so has no lists to guard.
	 * @param  array<class-string<Rule>, list<array{string, mixed}>>  $layers
	 * @return array<class-string<Rule>, list<array{string, mixed}>>
	 */
	private static function removeGuardLayer(array $layers): array
	{
		$guard = NoUnlistedNamespacedDeclarationsRule::class;
		if (isset($layers[$guard])) {
			$layers[$guard] = array_values(array_filter($layers[$guard], fn(array $layer) => $layer[0] !== self::GuardLayer));
			if ($layers[$guard] === []) {
				unset($layers[$guard]);
			}
		}

		return $layers;
	}


	/**
	 * The versions the code is checked for and the lowest of them, the one a rule asks about: the target, or the oldest
	 * DressCode fixes code for, with a warning.
	 * @return array{string, string}
	 */
	private function resolveTargetPhp(string $target): array
	{
		$version = Versions::findLowestVersion($target);
		if ($version !== null && version_compare($version, Config::MinPhpVersion, '>=')) {
			return [$target, $version];
		}

		$this->warnings['php'] = 'The target PHP ' . ($version ?? $target) . ' is older than PHP ' . Config::MinPhpVersion . ', the oldest DressCode fixes code for;'
			. ' the code is checked as PHP ' . Config::MinPhpVersion . ', so a fix may write syntax the target does not have.';
		return [Config::MinPhpVersion, Config::MinPhpVersion];
	}


	/** The line ending the profile names, or `majority` for the one the file uses most. */
	private static function resolveLineEnding(?string $lineEnding): string
	{
		return match ($lineEnding) {
			'LF' => "\n",
			'CRLF' => "\r\n",
			'platform' => PHP_EOL === "\r\n" ? "\r\n" : "\n",
			default => 'majority',
		};
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
			throw new ConfigurationException("Preset `$name`: {$e->getMessage()}", previous: $e);
		}

		$defaults = new Profile;
		foreach (self::ProjectDecisions as $key) {
			if ($profile->$key !== $defaults->$key) {
				throw new ConfigurationException("Preset `$name` sets `$key`, which the project decides, not a preset.");
			}
		}

		return $this->profiles[$class] = $profile;
	}


	/** An error said in a layer the reader has to be sent to, named in front of it; the configuration needs no pointing at. */
	private static function locate(ConfigurationException $e, string $layer): ConfigurationException
	{
		return $layer === 'the configuration'
			? $e
			: new ConfigurationException(ucfirst(self::formatLayer($layer)) . ": {$e->getMessage()}", $e->docs, $e);
	}


	private static function describeOverride(Override $override): string
	{
		return 'the override for ' . implode(', ', $override->paths);
	}


	/** The name of a layer as a message says it, with the code it names in backticks. */
	public static function formatLayer(string $layer): string
	{
		if (in_array($layer, ['the configuration', 'the command line', 'the caller'], true)) {
			return $layer;
		} elseif (str_starts_with($layer, $prefix = 'the override for ')) {
			return $prefix . '`' . implode('`, `', explode(', ', substr($layer, strlen($prefix)))) . '`';
		} elseif (preg_match('~^(preset|group) (.+)$~', $layer, $m)) {
			return "$m[1] `$m[2]`";
		}

		return "`$layer`";
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
	 * The rules a run narrowed by `only` keeps, per name it was given.
	 * @param  list<string>  $names
	 * @return list<array{string, ?class-string<Rule>, list<class-string<Rule>>}>  what the name was, the rule it names, and the rules it lets in
	 * @throws ConfigurationException
	 */
	private function resolveOnly(array $names): array
	{
		return array_map($this->expandName(...), $names);
	}


	/**
	 * The rules a name of `only` stands for: a rule for itself, a group for every rule it turns on,
	 * a preset for every rule it and its parents mention.
	 * @return array{string, ?class-string<Rule>, list<class-string<Rule>>}  what the name was, the rule it names, and the rules it stands for
	 * @throws ConfigurationException
	 */
	private function expandName(string $name): array
	{
		$group = RuleGroup::tryFrom($name);
		if ($group !== null) {
			return ["group $name", null, $this->findRulesOfGroup($group)];
		}

		$class = $this->registry->resolveRuleOrPreset($name);
		if (!is_a($class, Preset::class, allow_string: true)) {
			return [RuleInfo::of($class)->name, $class, [$class]];
		}

		$layers = $visited = $rules = [];
		$this->collectPreset($class, $layers, $visited);
		foreach ($layers as [, $profile]) {
			foreach (array_keys($profile->rules) as $rule) {
				$rules[] = $this->registry->resolveRule($rule);
			}
		}

		return ['preset ' . PresetInfo::of($class)->name, null, $rules];
	}


	/**
	 * A name of `only` that lets in nothing that runs would make a run that checks nothing and says it is
	 * clean; a rule that runs only where an override enables it is not such a name.
	 * @param  list<array{string, ?class-string<Rule>, list<class-string<Rule>>}>  $narrowed
	 * @param  array<class-string<Rule>, ResolvedRule>  $active
	 * @param  array<class-string<Rule>, ResolvedRule>  $inactive
	 * @param  array<class-string<Rule>, true>  $ofOverrides
	 * @throws ConfigurationException
	 */
	private static function checkOnly(array $narrowed, array $active, array $inactive, array $ofOverrides): void
	{
		foreach ($narrowed as [$name, $class, $rules]) {
			if (array_filter($rules, fn(string $rule) => isset($active[$rule]) || isset($ofOverrides[$rule]))) {
				continue;
			} elseif ($class === null) {
				throw new ConfigurationException('Option `--only` names ' . self::formatLayer($name) . ', which has no rule that runs here.');
			}

			$rule = $inactive[$class];
			throw new ConfigurationException(
				"Option `--only` names rule `$rule->name`, which does not run: $rule->inactive"
				. (str_starts_with((string) $rule->inactive, 'it needs ') ? '.' : "; turn it on with `--rule $rule->name`."),
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
					$this->warnings["fixRisky $name"] = "Rule `$name` is named in `fixRisky` but runs nowhere; the entry does nothing.";
				}
			}
		}
	}


	/**
	 * The rules some override of the configuration turns on, itself, by a group or by a preset, whichever file
	 * it applies to.
	 * @return array<class-string<Rule>, true>
	 * @throws ConfigurationException
	 */
	private function findRulesOfOverrides(Config $config): array
	{
		$rules = [];
		foreach ($config->overrides as $override) {
			foreach ($this->collectLayers([[self::describeOverride($override), $override]]) as [$source, $profile, $isPreset]) {
				try {
					foreach ($profile->groups as $group) {
						foreach ($this->findRulesOfGroup($group) as $class) {
							$rules[$class] = true;
						}
					}

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
	 * The rules the group turns on, those that carry it.
	 * @return list<class-string<Rule>>
	 */
	private function findRulesOfGroup(RuleGroup $group): array
	{
		return array_values(array_filter(
			$this->registry->rules,
			fn(string $class) => RuleInfo::of($class)->group === $group,
		));
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  list<array{string, mixed}>  $layers
	 * @param  bool  $types  whether the configuration gives the types of the code
	 * @param  bool  $explicit  whether a layer other than a preset mentions the rule
	 * @param  bool  $kept  whether `only` keeps the rule, or the run is not narrowed
	 * @param  bool  $fixRisky  whether the project accepts its fixes that may change what the code does
	 * @param  bool  $warnOnly  whether its violations only warn
	 * @throws ConfigurationException
	 */
	private function resolveRule(
		string $class,
		array $layers,
		string $phpTarget,
		bool $types,
		bool $explicit,
		bool $kept,
		bool $fixRisky,
		bool $warnOnly,
	): ResolvedRule
	{
		$info = RuleInfo::of($class);
		$last = $layers[count($layers) - 1][1];
		$php = $info->requires['php'] ?? null;
		$tooNew = $php !== null && !Versions::isSubset($phpTarget, $php);
		$unmet = $this->project->findUnmetRequirement($info->getRequiredPackages());
		$untyped = $info->typesRequired && !$types;
		// a preset may name such a rule whatever the project has; a project naming it asked for what it cannot get
		if ($untyped && $last !== false && $explicit) {
			throw new ConfigurationException("Rule `$info->name` needs the types of the code; set `types: phpstan` in the configuration and install `phpstan/phpstan` in the project.", docs: 'types#enable');
		}

		[$reason, $inactive] = match (true) {
			$last === false => ['turnedOff', 'turned off by ' . $layers[count($layers) - 1][0]],
			$tooNew => ['php', "it needs PHP $php and the target is $phpTarget"],
			$unmet !== null => ['package', 'it needs ' . self::describeRequirement(...$unmet)],
			$untyped => ['types', 'it needs the types of the code and the configuration sets no types'],
			!$kept => ['narrowed', 'the run is narrowed to other rules'],
			default => [null, null],
		};
		if ($tooNew && $last !== false && $explicit) {
			$this->warnings[$info->name] = "Rule `$info->name` needs PHP $php and the target is $phpTarget; skipped.";
		} elseif ($unmet !== null && $last !== false && $explicit) {
			$this->warnings[$info->name] = "Rule `$info->name` needs " . self::describeRequirement(...$unmet, quote: '`') . '; skipped.';
		}

		$options = [];
		if ($inactive === null) {
			[$options, $warnings] = RuleBuilder::processOptions($class, $layers);
			foreach ($warnings as $message) {
				$this->warnings["$info->name $message"] = "Rule `$info->name`: $message";
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
			$warnOnly,
			$reason,
		);
	}


	/** A package the project does not meet, said as what the rule needs and what the project has instead. */
	private static function describeRequirement(string $package, string $constraint, ?string $current, string $quote = ''): string
	{
		$needs = $quote . ($constraint === '*' ? $package : "$package $constraint") . $quote;
		return $current === null
			? "$needs and the project does not have it"
			: "$needs and the project is written for $current";
	}
}
