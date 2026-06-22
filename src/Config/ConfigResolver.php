<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Decision, Plugin, Profile, Rule, RuleInfo, Value, Values};
use PhpSyntax\SymbolKind;
use function count, is_array, is_string;


/**
 * Lays the profiles that decide for a file one over another, each above the presets it uses, and resolves every
 * decision and the rule owning it once into a ResolvedConfig.
 * @internal
 */
final class ConfigResolver
{
	/** @var array<string, string> */
	private array $warnings = [];

	/** @var array<string, Profile>  the presets by the path of their file */
	private array $presetFiles = [];

	/** @var list<string>  the preset files being laid, each used by the one before it */
	private array $using = [];

	/** @var \WeakMap<Config, array<class-string<Rule>, true>>  the rules some override of a resolved configuration turns on */
	private \WeakMap $rulesOfOverrides;


	public function __construct(
		private readonly PluginRegistry $registry,
		/** the directory a file in `use` of the configuration and its overrides is relative to */
		private readonly string $root = '.',
	) {
		$this->rulesOfOverrides = new \WeakMap;
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
	 * The configuration as data for a file the given overrides match: what every decision ends up with and where it came
	 * from, the rules owning those that take effect, and why a rule that does not run does not.
	 * @param  string  $phpTarget  the versions the code is written for as a Composer constraint, unless a profile says another
	 * @param  list<int>  $overrides  indexes of the overrides that match the file
	 * @param  ?Profile  $commandLine  laid over everything else
	 * @param  ?list<string>  $only  decisions, sections, presets and classes of rules the run is narrowed to
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
		$fixRisky = $warningRules = $fixRiskyPaths = $warnOnlyPaths = $use = [];
		$symbols = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		$php = $resolution = null;
		$decisionLayers = [];
		['layers' => $collected, 'repeated' => $repeated] = $this->collectLayers(self::listProfiles($config, $overrides, $commandLine));
		foreach ($collected as [$layer, $profile]) {
			try {
				if ($layer->kind === LayerKind::Preset) {
					$use[] = (string) $layer->name;
				}

				$php = $profile->targets['php'] ?? $php;
				$resolution = $profile->nameResolution ?? $resolution;

				foreach ([
					[SymbolKind::Function, $profile->namespaces['functions']],
					[SymbolKind::Constant, $profile->namespaces['constants']],
				] as [$kind, $names]) {
					foreach ($names as $name) {
						$symbols[$kind->name][self::toSymbolKey($kind, $name)] ??= [$name, $layer->describe()];
					}
				}

				if ($profile->decisions !== []) {
					new DecisionResolver($this->getCatalogue())->checkLayer($profile->decisions);
					$decisionLayers[] = [$layer, $profile->decisions];
				}

				foreach ($profile->fixRisky as $name) {
					$expanded = $this->expandName($name);
					$fixRisky += array_fill_keys($expanded->rules, true);
					$fixRiskyPaths += array_fill_keys($expanded->paths, true);
				}

				foreach ($profile->warnOnly as $name) {
					$expanded = $this->expandName($name);
					$warningRules += array_fill_keys($expanded->rules, true);
					$warnOnlyPaths += array_fill_keys($expanded->paths, true);
				}

			} catch (ConfigurationException $e) {
				throw self::locate($e, $layer);
			}
		}

		$narrowed = $only ? $this->resolveOnly($only) : null;
		// an override is resolved for a file it matches, so a name or an option it gets wrong would pass unnoticed until
		// such a file comes; each of them is resolved together with the configuration
		$resolvedOverrides = $overrides === []
			? array_map(fn(int $index) => $this->resolve($config, $phpTarget, [$index], $commandLine, $only), array_keys($config->overrides))
			: [];
		// the rules some override turns on, whichever file it applies to, kept for the overrides resolved later; those
		// resolved above go without, only what runs being read of them
		if ($overrides === []) {
			$ofOverrides = [];
			foreach ($resolvedOverrides as $resolvedOverride) {
				foreach ($resolvedOverride->getActiveRules() as $rule) {
					$ofOverrides[$rule->class] = true;
				}
			}

			$this->rulesOfOverrides[$config] = $ofOverrides;
		}

		$ofOverrides = $this->rulesOfOverrides[$config] ?? [];

		[$phpTarget, $phpVersion] = $this->raiseToMinPhpVersion($php ?? $phpTarget);
		$resolver = new DecisionResolver(
			$this->getCatalogue(),
			$phpTarget,
			certainNames: $resolution === 'certain',
		);
		$decisions = $resolver->resolve($decisionLayers);
		$values = $resolver->createValues($decisions, $narrowed === null ? null : $this->collectSelection($narrowed));
		$factories = $config->getRuleFactories();
		$byRule = $active = $inactive = [];
		foreach ($decisions as $path => $decision) {
			foreach ($decision->rules as $class) {
				$byRule[$class][$path] = $decision;
			}
		}

		foreach ($this->getCatalogue()->getRuleOrder() as $class) {
			$resolved = $this->resolveRule($class, $byRule[$class] ?? [], $resolver->findRuleReason($class), $values, $phpTarget, $factories[$class] ?? null, isset($fixRisky[$class]), isset($warningRules[$class]), isset($ofOverrides[$class]));
			$resolved->isActive() ? $active[$class] = $resolved : $inactive[$class] = $resolved;
		}

		$this->warnRepeated($repeated);
		if ($overrides === []) {
			$narrowed === null
				? $this->checkFixRisky($config, $active, $ofOverrides)
				: $this->checkOnly($narrowed, $active, $inactive, $ofOverrides, $use);
		}

		// one entry per symbol, spelled the way the first layer naming it spells it
		$bySource = fn(array $entries): array => array_column($entries, 1, 0);
		return new ResolvedConfig(
			[...array_values($active), ...array_values($inactive)],
			"\t",
			self::resolveLineEnding($values->get('file.lineEnding')),
			$phpVersion,
			$use,
			namespacedFunctions: $bySource($symbols[SymbolKind::Function->name]),
			namespacedConstants: $bySource($symbols[SymbolKind::Constant->name]),
			nameResolution: $resolution ?? 'uncertain',
			plugins: array_map(fn(string|Plugin $plugin) => is_string($plugin) ? $plugin : $plugin::class, [...$config->plugins, ...$commandLine instanceof Config ? $commandLine->plugins : []]),
			decisions: $decisions,
			values: $values,
			fixRisky: $fixRiskyPaths,
			warnOnly: $warnOnlyPaths,
		);
	}


	/**
	 * The catalogue of the registered rules, as the registry keeps it.
	 * @throws ConfigurationException
	 */
	public function getCatalogue(): Catalogue
	{
		return $this->registry->getCatalogue();
	}


	/**
	 * A rule runs where one of its requirements or facts takes effect and lies in the mask of the run; otherwise it says
	 * why it does not. A preset may decide what cannot run here; a project deciding it asked for what it cannot get,
	 * which is a warning for the target.
	 * @param  class-string<Rule>  $class
	 * @param  array<string, ResolvedDecision>  $decisions  those the rule declares
	 * @param  ?InactiveReason  $ruleReason  why the rule does not run whatever its values
	 * @param  ?\Closure(): Rule  $factory
	 * @throws ConfigurationException
	 */
	private function resolveRule(
		string $class,
		array $decisions,
		?InactiveReason $ruleReason,
		Values $values,
		string $phpTarget,
		?\Closure $factory,
		bool $fixRisky,
		bool $warnOnly,
		bool $ofOverride,
	): ResolvedRule
	{
		$own = array_filter($decisions, fn(ResolvedDecision $decision) => !$decision->decision->parameter);
		$effective = $ruleReason === null ? array_filter($own, fn(ResolvedDecision $decision) => $decision->inactive === null) : [];
		$reasons = $ruleReason === null ? [] : [$ruleReason];
		foreach ($ruleReason === null ? $own : [] as $decision) {
			if (!in_array($decision->inactive, $reasons, true)) {
				$reasons[] = $decision->inactive;
			}
		}
		$asked = array_keys(array_filter($own, function (ResolvedDecision $decision): bool {
			$top = $decision->layers[count($decision->layers) - 1] ?? null;
			return $top?->origin?->isProject() === true && !$top->isKept();
		}));
		$info = RuleInfo::of($class);
		if ($asked !== [] && $reasons === [InactiveReason::Php]) {
			$this->warnings[$class] = "Decision `$asked[0]` needs PHP {$info->requires['php']} and the target is $phpTarget; skipped.";
		}

		[$reason, $message] = match (true) {
			$effective !== [] && array_any($effective, fn(ResolvedDecision $decision) => $values->isSelected($decision->decision->path)) => [null, null],
			$effective !== [] => [InactiveReason::Narrowed, 'the run is narrowed to other decisions'],
			$reasons === [InactiveReason::Php] => [InactiveReason::Php, "it needs PHP {$info->requires['php']} and the target is $phpTarget"],
			array_any($own, fn(ResolvedDecision $decision) => $decision->layers !== []) => [InactiveReason::TurnedOff, 'its decisions are `keep`'],
			$ofOverride => [InactiveReason::OnlyOverride, 'only an override turns it on'],
			default => [InactiveReason::NotMentioned, 'no preset or layer of the configuration names its decisions'],
		};
		// who said the decisive value: of a decision that takes effect, or of the last one turned off
		$said = $reason === null ? $effective : array_filter($own, fn(ResolvedDecision $decision) => $decision->layers !== []);
		$top = array_values($said)[0]->layers ?? [];
		$source = $top === [] ? null : $top[count($top) - 1]->origin;
		return new ResolvedRule($class, $source, $message, $factory, $fixRisky, $warnOnly, $reason);
	}


	/**
	 * The paths the decisions of the run are narrowed to, those the names of `only` stand for.
	 * @param  list<ExpandedName>  $narrowed
	 * @return list<string>
	 */
	private function collectSelection(array $narrowed): array
	{
		return array_values(array_unique(array_merge(...array_map(fn(ExpandedName $name) => $name->paths, $narrowed))));
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
	 * The versions the code is checked for and the lowest of them, the one a rule asks about: the target, or the oldest
	 * DressCode fixes code for, with a warning.
	 * @return array{string, string}
	 */
	private function raiseToMinPhpVersion(string $target): array
	{
		$version = Versions::findLowestVersion($target);
		if ($version !== null && version_compare($version, Config::MinPhpVersion, '>=')) {
			return [$target, $version];
		}

		$this->warnings['php'] = 'The target PHP ' . ($version ?? $target) . ' is older than PHP ' . Config::MinPhpVersion . ', the oldest DressCode fixes code for;'
			. ' the code is checked as PHP ' . Config::MinPhpVersion . ', so a fix may write syntax the target does not have.';
		return [Config::MinPhpVersion, Config::MinPhpVersion];
	}


	/** The line ending the decision names, or `majority` for the one each file uses most. */
	private static function resolveLineEnding(Value $lineEnding): string
	{
		return match ($lineEnding->isKept() ? 'majority' : $lineEnding->getWord()) {
			'LF' => "\n",
			'CRLF' => "\r\n",
			default => 'majority',
		};
	}


	/**
	 * The profiles that decide for a file matching the overrides, lowest first, each with the name an error or an origin
	 * is told by.
	 * @param  list<int>  $overrides
	 * @return list<array{Layer, Profile}>
	 */
	private static function listProfiles(Config $config, array $overrides, ?Profile $commandLine = null): array
	{
		$profiles = [[new Layer(LayerKind::Configuration), $config]];
		foreach ($overrides as $index) {
			$profiles[] = [new Layer(LayerKind::Override, implode(', ', $config->overrides[$index]->paths)), $config->overrides[$index]->profile];
		}

		if ($commandLine !== null) {
			$profiles[] = [new Layer(LayerKind::CommandLine), $commandLine];
		}

		return $profiles;
	}


	/**
	 * The profiles as layers, lowest first: each of them above what it uses, in the order it names them, and a preset
	 * where it is named first; what a profile names that is already laid, it names for nothing.
	 * @param  list<array{Layer, Profile}>  $profiles  who says it and what it says
	 * @return array{layers: list<array{Layer, Profile}>, repeated: list<array{Layer, Layer, Layer}>}  the layers, each
	 *   who says it and what it says; and every entry named for nothing, as its layer, the one that laid it and the one
	 *   that named it again
	 * @throws ConfigurationException
	 */
	private function collectLayers(array $profiles): array
	{
		$layers = $visited = $repeated = [];
		foreach ($profiles as [$layer, $profile]) {
			$this->collectUsed($profile, $layer, $layers, $visited, $repeated);
			$layers[] = [$layer, $profile];
		}

		return ['layers' => $layers, 'repeated' => $repeated];
	}


	/**
	 * Lays the presets the profile uses, each above what it uses in turn, once, where it is named first; a preset
	 * that comes back to itself through what it uses is an error, unlike one named again by another layer, which
	 * does nothing.
	 * @param  list<array{Layer, Profile}>  $layers
	 * @param  array<string, Layer>  $visited  the name of a preset => the layer that laid it
	 * @param  list<array{Layer, Layer, Layer}>  $repeated
	 * @throws ConfigurationException
	 */
	private function collectUsed(Profile $profile, Layer $source, array &$layers, array &$visited, array &$repeated): void
	{
		foreach ($profile->use as $entry) {
			try {
				// a preset by its name, or a file of the same shape by its path, which is its name too
				$name = str_ends_with($entry, '.neon') ? NeonReader::resolvePresetFile($entry, $this->root) : $this->registry->resolvePreset($entry);
				$file = $this->registry->presets[$name] ?? $name;
			} catch (ConfigurationException $e) {
				throw self::locate($e, $source);
			} catch (\InvalidArgumentException $e) {
				throw self::locate(new ConfigurationException($e->getMessage(), previous: $e), $source);
			}

			$layer = new Layer(LayerKind::Preset, $name);
			if (in_array($name, $this->using, true)) {
				throw new ConfigurationException(($file === $name ? 'Preset file' : 'Preset') . " `$name` uses itself through `" . implode('`, `', array_slice($this->using, array_search($name, $this->using, true) + 1)) . '`; a preset cannot come back to itself.');
			} elseif (isset($visited[$name])) {
				$repeated[] = [$layer, $visited[$name], $source];
				continue;
			}

			$visited[$name] = $source;
			$preset = $this->presetFiles[$file] ??= NeonReader::readPreset($file, $file === $name ? null : $name);
			$this->using[] = $name;
			try {
				$this->collectUsed($preset, $layer, $layers, $visited, $repeated);
			} finally {
				array_pop($this->using);
			}

			$layers[] = [$layer, $preset];
		}
	}


	/**
	 * An entry named again where a layer below already laid it does nothing, and the run says so.
	 * @param  list<array{Layer, Layer, Layer}>  $repeated
	 */
	private function warnRepeated(array $repeated): void
	{
		foreach ($repeated as [$layer, $laidBy, $namedBy]) {
			// what a preset uses is not the project's to change
			if ($namedBy->kind !== LayerKind::Preset) {
				$this->warnings['repeated ' . $layer->describe() . ' ' . $namedBy->describe()] = ucfirst($namedBy->format()) . ' uses ' . $layer->format()
					. ', which ' . $laidBy->format() . ' already brings; the entry does nothing.';
			}
		}
	}


	/** An error said in a layer the reader has to be sent to, named in front of it; the configuration needs no pointing at. */
	private static function locate(ConfigurationException $e, Layer $layer): ConfigurationException
	{
		return $layer->kind === LayerKind::Configuration
			? $e
			: new ConfigurationException(ucfirst($layer->format()) . ": {$e->getMessage()}", $e->docs, $e);
	}


	/**
	 * The rules a run narrowed by `only` keeps, per name it was given.
	 * @param  list<string>  $names
	 * @return list<ExpandedName>
	 * @throws ConfigurationException
	 */
	private function resolveOnly(array $names): array
	{
		return array_map($this->expandName(...), $names);
	}


	/**
	 * What a name of `only`, `fixRisky` or `warnOnly` stands for: a decision or a section for the decisions under it, a
	 * rule for its own, a preset for every decision it and its parents make, each with the rules owning them.
	 * @throws ConfigurationException
	 */
	private function expandName(string $name): ExpandedName
	{
		$paths = array_keys($this->getCatalogue()->getDecisionsUnder($name));
		$preset = null;
		if ($paths === []) {
			$resolved = $this->registry->registerRuleOrResolvePreset($name);
			$class = $resolved->rule;
			if ($class !== null) {
				$requirements = array_filter($this->getCatalogue()->getDecisionsOf($class), fn(Decision $decision) => !$decision->parameter);
				return new ExpandedName($class, false, $class, [$class], array_keys($requirements));
			}

			$preset = (string) $resolved->preset;
			foreach ($this->collectLayers([[new Layer(LayerKind::Caller), new Profile(use: [$preset])]])['layers'] as [, $profile]) {
				array_push($paths, ...$this->collectPaths($profile->decisions));
			}
		}

		$paths = array_values(array_unique($paths));
		$rules = array_merge(...array_map($this->getCatalogue()->getRulesOf(...), $paths));
		return new ExpandedName($preset ?? $name, $preset !== null, null, array_values(array_unique($rules)), $paths);
	}


	/**
	 * The paths of the decisions a layer makes, every one under a structure it makes as a whole.
	 * @param  array<mixed>  $tree
	 * @return list<string>
	 */
	private function collectPaths(array $tree, string $prefix = ''): array
	{
		$paths = [];
		foreach ($tree as $key => $value) {
			$path = $prefix . $key;
			if ($this->getCatalogue()->find($path) !== null) {
				$paths[] = $path;
			} elseif (is_array($value)) {
				array_push($paths, ...$this->collectPaths($value, "$path."));
			} else {
				array_push($paths, ...array_keys($this->getCatalogue()->getDecisionsUnder($path)));
			}
		}

		return $paths;
	}


	/**
	 * A name of `only` that lets in nothing that runs would make a run that checks nothing and says it is clean, and
	 * a section or a preset of which only some rules run here would make one that checks less than it says; a rule that
	 * runs only where an override enables it counts as running.
	 * @param  list<ExpandedName>  $narrowed
	 * @param  array<class-string<Rule>, ResolvedRule>  $active
	 * @param  array<class-string<Rule>, ResolvedRule>  $inactive
	 * @param  array<class-string<Rule>, true>  $ofOverrides
	 * @param  list<string>  $use  the presets the run lays
	 * @throws ConfigurationException
	 */
	private function checkOnly(array $narrowed, array $active, array $inactive, array $ofOverrides, array $use): void
	{
		foreach ($narrowed as $expanded) {
			[$class, $rules] = [$expanded->rule, $expanded->rules];
			$running = array_filter($rules, fn(string $rule) => isset($active[$rule]) || isset($ofOverrides[$rule]));
			if ($class !== null) {
				if (!$running) {
					throw new ConfigurationException("Option `--only` names rule `$class`, which cannot run here: {$inactive[$class]->inactiveMessage}.");
				}

				continue;
			}

			// left out is what the project turned off, and where it does not use the name what the name would bring; a rule
			// its standard turns off or that cannot run here, too new for the target, is not
			$collective = PluginRegistry::abbreviate($expanded->name);
			$laid = in_array($collective, array_map(PluginRegistry::abbreviate(...), $use), true);
			$left = array_filter($rules, fn(string $rule) => match ($inactive[$rule]->inactiveReason ?? null) {
				InactiveReason::TurnedOff => $inactive[$rule]->source?->isProject() === true,
				InactiveReason::NotMentioned => !$laid,
				default => false,
			});
			// a preset the configuration does not use is brought by `--use`, a decision it does not make by `--set`
			$hint = match (true) {
				$expanded->preset => "`--use $collective --only $collective` runs all its rules",
				$this->getCatalogue()->find($collective) !== null => "`--set $collective=<value>` decides it for the run",
				default => '`--set <path>=<value>` decides one of them for the run',
			};
			if (!$running) {
				throw new ConfigurationException('Option `--only` names ' . $expanded->format() . ', which has no rule that runs here' . ($laid ? '.' : "; $hint."));
			} elseif ($left) {
				// a rule left out is named by its requirements, which is what the user writes
				$names = array_map(fn(string $path) => "`$path`", array_keys(array_filter(
					array_merge(...array_map(fn(string $rule) => $this->getCatalogue()->getDecisionsOf($rule), array_values($left))),
					fn(Decision $decision) => !$decision->parameter,
				)));
				$this->warnings["only $expanded->name"] = 'Option `--only` keeps ' . count($running) . ' of the ' . (count($running) + count($left))
					. ' rules of ' . $expanded->format() . ' that may run here'
					. ($laid || !$expanded->preset
						? ', without ' . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' and ' . (count($names) - 5) . ' more' : '')
							. ($laid ? ', which the configuration turns off.' : ", which the configuration does not decide; $hint.")
						: "; $hint.");
			}
		}
	}


	/**
	 * A name in fixRisky whose rules run nowhere, not even where an override turns them on, makes the entry a line that
	 * does nothing, which is worth a warning.
	 * @param  array<class-string<Rule>, ResolvedRule>  $active
	 * @param  array<class-string<Rule>, true>  $ofOverrides
	 * @throws ConfigurationException
	 */
	private function checkFixRisky(Config $config, array $active, array $ofOverrides): void
	{
		foreach (self::listProfiles($config, array_keys($config->overrides)) as [$layer, $profile]) {
			foreach ($profile->fixRisky as $entry) {
				try {
					$expanded = $this->expandName($entry);
				} catch (ConfigurationException $e) {
					throw self::locate($e, $layer);
				}

				if (!array_filter($expanded->rules, fn(string $class) => isset($active[$class]) || isset($ofOverrides[$class]))) {
					$this->warnings["fixRisky $expanded->name"] = ucfirst($expanded->format())
						. ' is named in `fixRisky` but runs nowhere; the entry does nothing.';
				}
			}
		}
	}
}
