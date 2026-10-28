<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;

use DressCode\{Config, ConfigurationException, Decision, DecisionKind, Plugin, Profile, Rule, RuleInfo, Value, Values};
use DressCode\Domains\Map;
use PhpSyntax\SymbolKind;
use function count, is_array, is_string;


/**
 * Lays the profiles that decide for a file one over another, each above the presets it uses, what the installed
 * packages say under them all, and resolves every decision and the rule owning it once into a ResolvedConfig.
 * @internal
 */
final class ConfigResolver
{
	/** the decision that lays the maps of the upgrading data of the packages under those of the project */
	private const PackagesDecision = 'upgrading.packages';

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
		/**
		 * @var list<UpgradingData>  what the installed packages say, the maps of each laid under the project where it says
		 * `upgrading.packages: yes`
		 */
		private readonly array $upgradingData = [],
		/** the packages the project stands on, which decide whether a rule requiring one runs */
		private readonly ProjectPackages $project = new ProjectPackages,
		/** the run can get the types the configuration or the command line asks for; without them it resolves as if it asked for none */
		private readonly bool $typesAvailable = true,
		/** the directory a file in `use` of the configuration and its overrides is relative to */
		private readonly string $root = '.',
		/** @var list<string>  the packages whose plugins the project does not let in, so the maps of their rules are unknown */
		private readonly array $pluginsSkipped = [],
		/**
		 * @var list<string>  presets laid over those the configuration uses and beneath its own decisions and its overrides,
		 * so that the project keeps the last word, as `upgrade` lays its sets
		 */
		private readonly array $laid = [],
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
		$fixRisky = $fixRiskyPaths = $reportOnly = $severities = $use = $suppressionComments = $suppressionExamples = [];
		$symbols = [SymbolKind::Function->name => [], SymbolKind::Constant->name => []];
		$php = $resolution = null;
		$typeAnalysis = ($commandLine instanceof Config ? $commandLine->typeAnalysis : null) ?? $config->typeAnalysis;
		$decisionLayers = [];
		// what the packages declare lies under every layer, which may add to it
		foreach ($this->upgradingData as $package) {
			self::addSymbols($symbols, $package->namespaces, $package->layer->describe());
		}

		['layers' => $collected, 'repeated' => $repeated] = $this->collectLayers(self::listProfiles($config, $overrides, $commandLine));
		foreach ($collected as [$layer, $profile]) {
			try {
				if ($layer->kind === LayerKind::Preset) {
					$use[] = (string) $layer->name;
				}

				$php = $profile->targets['php'] ?? $php;
				$resolution = $profile->nameResolution ?? $resolution;

				self::addSymbols($symbols, $profile->namespaces, $layer->describe());

				if ($profile->decisions !== []) {
					new DecisionResolver($this->getCatalogue(), translator: $this->registry->translator)->checkLayer($profile->decisions);
					$decisionLayers[] = [$layer, $profile->decisions];
				}

				foreach ($profile->fixRisky as $name) {
					$expanded = $this->expandName($name);
					$fixRisky += array_fill_keys($expanded->rules, true);
					$fixRiskyPaths += array_fill_keys($expanded->paths, true);
				}

				foreach ($profile->reportOnly as $name) {
					$reportOnly += array_fill_keys($this->expandName($name)->paths, true);
				}

				$severities[] = $this->collectSeverity($profile->severity);

				foreach ($profile->suppressionComments as $pattern => $entry) {
					// a pattern a later layer says again is that layer's, the example with it
					$suppressionComments[$pattern] = array_values(array_unique(array_merge(
						...array_map($this->resolveSuppressedName(...), $entry['decisions']),
					)));
					$suppressionExamples[$pattern] = $entry['example'];
				}

			} catch (ConfigurationException $e) {
				throw self::locate($e, $layer);
			}
		}

		$narrowed = $only ? $this->resolveOnly($only) : null;
		// an override is resolved for a file it matches, so a name or an option it gets wrong would pass unnoticed until
		// such a file comes; each of them is resolved together with the configuration
		$resolvedOverrides = $overrides === []
			? array_map(
				fn(int $index) => $this->resolve($config, $phpTarget, [$index], $commandLine, $only),
				array_keys($config->overrides),
			)
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

		[$phpTarget, $phpVersion] = $this->resolvePhpTarget($php ?? $phpTarget);
		$resolver = new DecisionResolver(
			$this->getCatalogue(),
			$phpTarget,
			$this->project,
			typesAnalyzed: $typeAnalysis !== null && $this->typesAvailable,
			certainNames: $resolution === 'certain',
			translator: $this->registry->translator,
		);
		$decisions = $resolver->resolve($decisionLayers);
		if ($packageLayers = $this->collectPackageMaps($decisions, $narrowed)) {
			$decisions = $resolver->resolve([...$packageLayers, ...$decisionLayers]);
		}

		$values = $resolver->createValues($decisions, $narrowed === null ? null : $this->collectSelection($narrowed));
		foreach ($this->getCatalogue()->getDecisions() as $path => $decision) {
			// a map the run is narrowed away from is refused where its entries are wrong all the same, no rule reading it
			if (
				$decision->domain instanceof Map
				&& $decision->domain->grammar !== null
				&& !$values->isKept($path)
				&& !$values->isSelected($path)
			) {
				$values->readMap($path);
			}
		}

		$factories = $config->getRuleFactories() + $this->registry->ruleFactories;
		$byRule = $active = $inactive = [];
		foreach ($decisions as $path => $decision) {
			foreach ($decision->rules as $class) {
				$byRule[$class][$path] = $decision;
			}
		}

		foreach ($this->getCatalogue()->getRuleOrder() as $class) {
			$resolved = $this->resolveRule(
				$class,
				$byRule[$class] ?? [],
				$resolver->findRuleReason($class),
				$values,
				$phpTarget,
				$factories[$class] ?? null,
				isset($fixRisky[$class]),
				isset($ofOverrides[$class]),
			);
			$resolved->isActive() ? $active[$class] = $resolved : $inactive[$class] = $resolved;
		}

		$this->warnRepeated($repeated);
		if ($overrides === []) {
			$narrowed === null
				? $this->checkFixPolicies($config, $active, $ofOverrides)
				: $this->checkOnly($narrowed, $active, $inactive, $ofOverrides, $use);
		}

		// one entry per symbol, spelled the way the first layer naming it spells it
		$bySource = fn(array $entries): array => array_column($entries, 1, 0);
		return new ResolvedConfig(
			[...array_values($active), ...array_values($inactive)],
			self::resolveIndent($values->get('indentation.unit')),
			self::resolveLineEnding($values->get('file.lineEnding')),
			$phpVersion,
			$use,
			namespacedFunctions: $bySource($symbols[SymbolKind::Function->name]),
			namespacedConstants: $bySource($symbols[SymbolKind::Constant->name]),
			nameResolution: $resolution ?? 'uncertain',
			lineLength: self::resolveLineLength($values->get('file.lineLength')),
			tabWidth: $values->get('indentation.tabWidth')->getCountRange()[0],
			typeAnalysis: $this->typesAvailable ? $typeAnalysis : null,
			packageTargets: array_diff_key(RunnerFactory::mergeTargets($config, $commandLine), ['php' => true]),
			plugins: array_map(
				fn(string|Plugin $plugin) => is_string($plugin) ? $plugin : $plugin::class,
				[...$config->plugins, ...$commandLine instanceof Config ? $commandLine->plugins : []],
			),
			suppressionComments: $suppressionComments,
			suppressionExamples: array_filter($suppressionExamples),
			decisions: $decisions,
			values: $values,
			fixRisky: $fixRiskyPaths,
			reportOnly: $reportOnly,
			warningDecisions: self::findWarningDecisions($decisions, array_column($collected, 0), $severities),
			overrides: $resolvedOverrides,
		);
	}


	/**
	 * The decisions each of the standards makes, resolved alone.
	 * @return array<string, array<string, ResolvedDecision>>  standard => path => its decision
	 * @throws ConfigurationException
	 */
	public function resolveStandards(string $phpTarget): array
	{
		$standards = [];
		foreach (CorePlugin::Standards as $standard) {
			$standards[$standard] = $this->resolve(new Config(use: ["dresscode/$standard"]), $phpTarget)->decisions;
		}

		return $standards;
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
	 * The maps of the upgrading files of the installed packages, and the requirements of the rules of their plugins they
	 * turn on, as layers under every other one, where `upgrading.packages` is `yes`, or where the requirement laying a
	 * file is not `keep` (`upgrading.php.deprecatedApi` for the data of PHP). A run narrowed by `only` gets the maps of
	 * a package or a library it names, and those of the others only where another of its names covers the map, or the
	 * requirement laying them.
	 * @param  array<string, ResolvedDecision>  $decisions
	 * @param  ?list<ExpandedName>  $narrowed
	 * @return list<array{Layer, array<string, mixed>}>
	 * @throws ConfigurationException  where a file turns on a rule another plugin or the core brings
	 */
	private function collectPackageMaps(array $decisions, ?array $narrowed): array
	{
		$named = $covered = [];
		foreach ($narrowed ?? [] as $expanded) {
			$expanded->package ? $named[$expanded->name] = true : $covered += array_fill_keys($expanded->paths, true);
		}

		$layers = [];
		foreach ($this->upgradingData as $package) {
			$requirement = $package->layer->decision;
			$built = $package->buildDecisions(function (string $map) use ($package, $requirement, $narrowed, $named, $covered): ?string {
				$path = $this->findDataPath($map);
				if ($path === null && !in_array($package->layer->package, $this->pluginsSkipped, true)) { // the plugin skipped says so
					$this->warnings[$package->layer->describe() . " $map"] = "Map `$map`, which "
						. $package->layer->formatReference()
						. ' sets, is not known to this DressCode; skipped.';
				} elseif (
					$path !== null
					&& !$this->getCatalogue()->find($path)?->domain instanceof Map
					&& !$this->isOfPackagePlugin($path, $package->layer->package)
				) {
					throw new ConfigurationException(
						$package->layer->formatReference() . " turns on `$path`, which is no rule of the plugin of its package.",
					);
				}

				return $path !== null
					&& ($narrowed === null
						|| isset($named[$package->layer->package ?? ''])
						|| isset($named[$package->package])
						|| isset($covered[$path])
						|| isset($covered[$requirement ?? '']))
					? $path
					: null;
			});
			$laid = $requirement === null
				? ($decisions[self::PackagesDecision] ?? null)?->value->getFlag()
				: ($decisions[$requirement] ?? null)?->value->isKept() === false;
			if ($laid) {
				array_push($layers, ...$built);
			}
		}

		return $layers;
	}


	/** Whether the decision is one of the rules of the plugin the package ships. */
	private function isOfPackagePlugin(string $path, ?string $package): bool
	{
		$section = $package === null ? null : $this->registry->findPackageSection($package);
		return $section !== null && str_starts_with($path, "$section.");
	}


	/**
	 * The decision a key of a section of an upgrading file sets: a map of `upgrading.api` by its name, a map of a plugin
	 * or a requirement it turns on by its path.
	 */
	private function findDataPath(string $key): ?string
	{
		$decision = $this->getCatalogue()->find($key);
		return match (true) {
			$this->getCatalogue()->find("upgrading.api.$key")?->domain instanceof Map => "upgrading.api.$key",
			$decision?->domain instanceof Map, $decision?->isRequirement() === true => $key,
			default => null,
		};
	}


	/**
	 * A rule runs where one of its requirements or facts takes effect and lies in the mask of the run; otherwise it says
	 * why it does not. A preset may decide what cannot run here; a project deciding it asked for what it cannot get,
	 * which is an error for the types and a warning for the target and for a package, unless another rule of the
	 * decision runs.
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
		bool $ofOverride,
	): ResolvedRule
	{
		$own = array_filter($decisions, fn(ResolvedDecision $decision) => $decision->decision->kind !== DecisionKind::Parameter);
		$effective = $ruleReason === null ? array_filter($own, fn(ResolvedDecision $decision) => $decision->inactive === null) : [];
		$asked = array_filter($own, function (ResolvedDecision $decision): bool {
			$top = $decision->findTopLayer();
			return $top?->origin?->isProject() === true && !$top->isKept() && $decision->inactive !== null;
		});
		// a value a group gives a decision its target leaves out is skipped silently, as one of a preset; the types never are
		$named = array_keys(array_filter($asked, fn(ResolvedDecision $decision) => !$decision->cascaded));
		$asked = array_keys($asked);
		$info = RuleInfo::fromRule($class);
		if ($asked !== [] && $ruleReason === InactiveReason::Types) {
			throw new ConfigurationException($this->typesAvailable
				? "Decision `$asked[0]` needs the types of the code; set `typeAnalysis: phpstan` in the configuration and install `phpstan/phpstan` beside DressCode."
				: "Decision `$asked[0]` needs the types of the code, but `phpstan/phpstan` is not installed beside DressCode.", docs: 'types#enable');
		} elseif ($named !== [] && $ruleReason === InactiveReason::Php) {
			$this->warnings[$class] = "Decision `$named[0]` needs PHP {$info->requires['php']} and the target is $phpTarget; skipped.";
		} elseif ($named !== [] && $ruleReason === InactiveReason::Package) {
			$this->warnings[$class] = "Decision `$named[0]` needs "
				. ($this->project->findUnmetRequirement($info->getRequiredPackages()) ?? throw new \LogicException)->format()
				. '; skipped.';
		}

		[$reason, $message] = match (true) {
			$effective !== []
				&& array_any($effective, fn(ResolvedDecision $decision) => $values->isSelected($decision->decision->path)) => [
					null,
					null,
				],
			$effective !== [] => [InactiveReason::Narrowed, 'the run is narrowed to other decisions'],
			$ruleReason === InactiveReason::Php => [
				InactiveReason::Php,
				"it needs PHP {$info->requires['php']} and the target is $phpTarget",
			],
			$ruleReason === InactiveReason::Package => [InactiveReason::Package, 'it needs a package the project does not have'],
			$ruleReason === InactiveReason::Types
				&& array_any(
					$own,
					fn(ResolvedDecision $decision) => $decision->decision->isRequirement() && !$decision->value->isKept(),
				) => [InactiveReason::Types, $this->typesAvailable
					? 'it needs the types of the code and the configuration sets no types'
					: 'it needs the types of the code and phpstan/phpstan is not installed beside DressCode'],
			array_any($own, fn(ResolvedDecision $decision) => $decision->layers !== []) => [
				InactiveReason::TurnedOff,
				'its decisions are `keep`',
			],
			$ofOverride => [InactiveReason::OnlyOverride, 'only an override turns it on'],
			default => [InactiveReason::NotMentioned, 'no preset or layer of the configuration names its decisions'],
		};
		// who said the decisive value: of a decision that takes effect, or of the last one turned off
		$said = $reason === null ? $effective : array_filter($own, fn(ResolvedDecision $decision) => $decision->layers !== []);
		$top = array_values($said)[0]->layers ?? [];
		$source = $top === [] ? null : $top[count($top) - 1]->origin;
		return new ResolvedRule($class, $source, $reason, $message, $factory, $fixRisky);
	}


	/**
	 * The severity a layer says of each requirement its names cover, the most specific name winning: a decision, a
	 * deeper group, a shallower one, a section, the class of a rule, a preset.
	 * @param  array<string, string>  $names  name => `warning` or `error`
	 * @return array<string, array{int, string}>  path => the rank of the name and the severity
	 * @throws ConfigurationException
	 */
	private function collectSeverity(array $names): array
	{
		$severity = $by = [];
		foreach ($names as $name => $value) {
			$expanded = $this->expandName($name);
			$paths = array_filter(
				$expanded->paths,
				fn(string $path) => $this->getCatalogue()->find($path)?->kind !== DecisionKind::Parameter,
			);
			if ($paths === []) {
				throw new ConfigurationException("Name `$name` in `severity` covers no decision that reports; a parameter has no severity of its own.");
			}

			$rank = match (true) {
				$expanded->preset => 0,
				$expanded->rule !== null => 1,
				$this->getCatalogue()->find($name) !== null => PHP_INT_MAX,
				default => 1 + count(explode('.', $expanded->name)),
			};
			foreach ($paths as $path) {
				[$was, $wasValue] = $severity[$path] ?? [-1, null];
				if ($was === $rank && $wasValue !== $value) {
					throw new ConfigurationException("Names `{$by[$path]}` and `$name` in `severity` say two severities of `$path`; name it alone.");
				} elseif ($was < $rank) {
					$severity[$path] = [$rank, $value];
					$by[$path] = $name;
				}
			}
		}

		return $severity;
	}


	/**
	 * The requirements whose violations only warn: a severity holds for a decision whose value the layer saying it, or
	 * one below it, said, so a layer writing the value anew without a severity makes it an error again.
	 * @param  array<string, ResolvedDecision>  $decisions
	 * @param  list<Layer>  $layers  from the lowest
	 * @param  list<array<string, array{int, string}>>  $severities  of each layer, by path
	 * @return array<string, true>
	 */
	private static function findWarningDecisions(array $decisions, array $layers, array $severities): array
	{
		$warnings = [];
		foreach ($decisions as $path => $decision) {
			$top = $decision->findTopLayer()?->origin;
			$from = $top === null ? 0 : max(0, (int) array_search($top, $layers, strict: true));
			for ($i = count($layers) - 1; $i >= $from; $i--) {
				if (isset($severities[$i][$path])) {
					if ($severities[$i][$path][1] === 'warning') {
						$warnings[$path] = true;
					}

					break;
				}
			}
		}

		return $warnings;
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
	 * The decisions a name of `suppressionComments` silences.
	 * @return list<string>
	 * @throws ConfigurationException
	 */
	private function resolveSuppressedName(string $name): array
	{
		return $this->registry->expandSuppressedName($name)
			?: throw new ConfigurationException("`suppressionComments` names `$name`, which is no decision, section or rule.");
	}


	/**
	 * Adds the functions and the constants the namespaces of a layer declare, each spelled the way the first layer
	 * naming it spells it.
	 * @param  array<string, array<string, array{string, string}>>  $symbols
	 * @param  array{functions: list<string>, constants: list<string>}  $namespaces
	 */
	private static function addSymbols(array &$symbols, array $namespaces, string $layer): void
	{
		foreach ([[SymbolKind::Function, 'functions'], [SymbolKind::Constant, 'constants']] as [$kind, $key]) {
			foreach ($namespaces[$key] as $name) {
				$symbols[$kind->name][self::toSymbolKey($kind, $name)] ??= [$name, $layer];
			}
		}
	}


	/**
	 * The key two spellings of one symbol share, the way PHP reads them: a function in any letter case, a constant
	 * in any letter case of its namespace but not of its own name.
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
	 * The versions the code is checked for and the lowest of them, the one a rule asks about. A target older than the
	 * version the rules are tested from stays as it is, with a warning, the rules of what came later leaving its code
	 * alone; one without a lowest version is checked as that version.
	 * @return array{string, string}
	 */
	private function resolvePhpTarget(string $target): array
	{
		$version = Versions::findLowestVersion($target);
		if ($version === null) {
			$this->warnings['php'] = "The target PHP $target names no oldest version; the code is checked as PHP " . Config::MinPhpVersion
				. ', so a fix may write syntax the target does not have.';
			return [Config::MinPhpVersion, Config::MinPhpVersion];

		} elseif (version_compare($version, Config::MinPhpVersion, '<')) {
			$this->warnings['php'] = "The target PHP $version is older than " . Config::MinPhpVersion
				. ', which DressCode tests; the fixes of PHP 8 constructs stay off.';
		}

		return [$target, $version];
	}


	/** The characters of a level of indentation the unit names, a tab where it is `keep`. */
	private static function resolveIndent(Value $unit): string
	{
		return $unit->isKept() || $unit->getWord() === 'tab' ? "\t" : str_repeat(' ', (int) $unit->getWord());
	}


	/** The line ending the decision names; null for the one each file uses most. */
	private static function resolveLineEnding(Value $lineEnding): ?string
	{
		return match ($lineEnding->isKept() ? null : $lineEnding->getWord()) {
			'LF' => "\n",
			'CRLF' => "\r\n",
			default => null,
		};
	}


	/** The widest line, null for none. */
	private static function resolveLineLength(Value $length): ?int
	{
		return $length->content === 'none' ? null : $length->getCountRange()[0];
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
			$profiles[] = [
				new Layer(LayerKind::Override, implode(', ', $config->overrides[$index]->paths)),
				$config->overrides[$index]->profile,
			];
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
			if ($layer->kind === LayerKind::Configuration && $this->laid !== []) {
				$this->collectUsed(new Profile(use: $this->laid), new Layer(LayerKind::Caller), $layers, $visited, $repeated);
			}

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
			if ($this->registry->isPluginPackage($entry)) { // it lets the plugin of the package in, which is no layer
				continue;
			}

			try {
				// a preset by its name, or a file of the same shape by its path, which is its name too
				$name = str_ends_with($entry, '.neon')
					? NeonReader::resolvePresetFile($entry, $this->root)
					: $this->registry->resolvePreset($entry);
				$file = $this->registry->presets[$name] ?? $name;
			} catch (ConfigurationException $e) {
				throw self::locate($e, $source);
			} catch (\InvalidArgumentException $e) {
				throw self::locate(new ConfigurationException($e->getMessage(), previous: $e), $source);
			}

			$layer = new Layer(LayerKind::Preset, $name);
			if (in_array($name, $this->using, true)) {
				$cycle = implode('`, `', array_slice($this->using, array_search($name, $this->using, strict: true) + 1));
				throw new ConfigurationException(($file === $name ? 'Preset file' : 'Preset') . " `$name` uses itself through `$cycle`; a preset cannot come back to itself.");
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
			// what a preset or the caller lays is not the project's to change, and the command line reaches what an override
			// does not
			if (
				$namedBy->kind !== LayerKind::Preset
				&& $namedBy->kind !== LayerKind::Caller
				&& $laidBy->kind !== LayerKind::Caller
				&& !($laidBy->kind === LayerKind::Override && $namedBy->kind === LayerKind::CommandLine)
			) {
				$this->warnings['repeated ' . $layer->describe() . ' ' . $namedBy->describe()] = ucfirst($namedBy->formatReference())
					. ' uses ' . $layer->formatReference()
					. ', which ' . $laidBy->formatReference() . ' already brings; the entry does nothing.';
			}
		}
	}


	/** An error said in a layer the reader has to be sent to, named in front of it; the configuration needs no pointing at. */
	private static function locate(ConfigurationException $e, Layer $layer): ConfigurationException
	{
		return $layer->kind === LayerKind::Configuration
			? $e
			: new ConfigurationException(ucfirst($layer->formatReference()) . ": {$e->getMessage()}", $e->docs, $e);
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
	 * What a name of `only`, `fixRisky` or `severity` stands for: a decision or a section for the decisions under it,
	 * a package of upgrading data for what its files set, a library for what the files about it set, both for a name of
	 * both, a rule for its own, a preset for every decision it and its parents make, each with the rules owning them. A
	 * section named like a preset is written `section.*`, the bare name being refused as both.
	 * @throws ConfigurationException
	 */
	public function expandName(string $name): ExpandedName
	{
		$package = $this->findDataPackage($name);
		if ($package !== null) {
			$paths = [];
			foreach ($this->upgradingData as $data) {
				if ($data->layer->package === $package || $data->package === $package) {
					$keys = [...array_keys($data->maps), ...array_keys($data->requirements)];
					array_push($paths, ...array_filter(array_map($this->findDataPath(...), $keys)));
					// the data of PHP are reported under the requirement laying them
					if ($data->layer->decision !== null) {
						$paths[] = $data->layer->decision;
					}
				}
			}

			$paths = array_values(array_unique($paths));
			$rules = array_merge(...array_map($this->getCatalogue()->getRulesOf(...), $paths));
			return new ExpandedName($package, false, null, array_values(array_unique($rules)), $paths, package: true);
		}

		$section = str_ends_with($name, '.*') ? substr($name, 0, -2) : null;
		$paths = array_keys($this->getCatalogue()->getDecisionsUnder($section ?? $name));
		$preset = $paths !== [] && $section === null && !str_contains($name, '.') ? $this->registry->findPreset($name) : null;
		if ($preset !== null) {
			throw new ConfigurationException("Name `$name` is both a section of decisions and the preset `$preset`; write `$name.*` for the section or `$preset` for the preset.");
		}

		$preset = null;
		if ($paths === [] && $section !== null) {
			throw new ConfigurationException("Unknown section `$section`.");
		} elseif ($paths === [] && in_array($name, $this->registry->rules, true)) {
			$requirements = array_filter(
				$this->getCatalogue()->getDecisionsOf($name),
				fn(Decision $decision) => $decision->kind !== DecisionKind::Parameter,
			);
			return new ExpandedName($name, false, $name, [$name], array_keys($requirements));
		} elseif ($paths === [] && class_exists($name) && is_subclass_of($name, Rule::class)) {
			throw new ConfigurationException("Rule `$name` is not registered; add it to `rules` of the configuration.");
		} elseif ($paths === []) {
			$preset = $this->registry->findPreset($name) ?? throw $this->registry->createUnknownNameException($name);
			foreach ($this->collectLayers([[new Layer(LayerKind::Caller), new Profile(use: [$preset])]])['layers'] as [, $profile]) {
				array_push($paths, ...$this->collectPaths($profile->decisions));
			}
		}

		$paths = array_values(array_unique($paths));
		$rules = array_merge(...array_map($this->getCatalogue()->getRulesOf(...), $paths));
		return new ExpandedName($preset ?? $section ?? $name, $preset !== null, null, array_values(array_unique($rules)), $paths);
	}


	/**
	 * The full name of the installed package the name stands for, one shipping upgrading files or one they are about,
	 * one of the core without its vendor too.
	 */
	private function findDataPackage(string $name): ?string
	{
		$names = [$name, PluginRegistry::qualify($name)];
		foreach ($this->upgradingData as $data) {
			if ($data->layer->name !== null && in_array($data->layer->package, $names, true)) {
				return $data->layer->package;
			} elseif (in_array($data->package, $names, true)) {
				return $data->package;
			}
		}

		return null;
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
		$skipped = [];
		foreach ($narrowed as $expanded) {
			[$class, $rules] = [$expanded->rule, $expanded->rules];
			$running = array_filter($rules, fn(string $rule) => isset($active[$rule]) || isset($ofOverrides[$rule]));
			// a name whose rules the project cannot run, too new for its target or for its packages, is skipped, so that
			// one command of `--only` serves projects of several targets
			if (
				!$running
				&& $rules !== []
				&& array_all($rules, fn(string $rule) => in_array(
					$inactive[$rule]->inactiveReason ?? null,
					[InactiveReason::Php, InactiveReason::Package],
					true,
				))
			) {
				$skipped[$expanded->name] = [
					'Option `--only` names ' . ($class === null ? $expanded->format() : "rule `$class`")
					. ", which cannot run here: {$inactive[$rules[array_key_first($rules)]]->inactiveMessage}",
					$rules,
				];
				continue;
			}

			if ($class !== null) {
				if (!$running) {
					throw new ConfigurationException("Option `--only` names rule `$class`, which cannot run here: {$inactive[$class]->inactiveMessage}.");
				}

				continue;
			}

			// left out is what the project turned off, and where it does not use the name what the name would bring; a rule
			// its standard turns off or that cannot run here, too new for the target or needing the types, is not
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
				$expanded->package
					=> 'its upgrading files reach no version of a library the project has, or `upgrading.packages` is not `yes`',
				$this->getCatalogue()->find($collective) !== null => "`--set $collective=<value>` decides it for the run",
				default => '`--set <path>=<value>` decides one of them for the run',
			};
			if (!$running) {
				throw new ConfigurationException('Option `--only` names ' . $expanded->format() . ', which has no rule that runs here' . ($laid ? '.' : "; $hint."));
			} elseif ($left) {
				// a rule left out is named by its requirements, which is what the user writes
				$names = array_map(fn(string $path) => "`$path`", array_keys(array_filter(
					array_merge(...array_map(fn(string $rule) => $this->getCatalogue()->getDecisionsOf($rule), array_values($left))),
					fn(Decision $decision) => $decision->kind !== DecisionKind::Parameter,
				)));
				$this->warnings["only $expanded->name"] = 'Option `--only` keeps ' . count($running) . ' of the ' . (count(
					$running,
				) + count(
					$left,
				))
					. ' rules of ' . $expanded->format() . ' that may run here'
					. ($laid || !$expanded->preset
						? ', without ' . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5
							? ' and ' . (count($names) - 5) . ' more'
							: '')
							. ($laid ? ', which the configuration turns off.' : ", which the configuration does not decide; $hint.")
						: "; $hint.");
			}
		}

		if ($skipped !== [] && count($skipped) === count($narrowed)) {
			throw new ConfigurationException(reset($skipped)[0] . '.');
		}

		foreach ($skipped as $name => [$message, $rules]) {
			// a rule whose decision the project wrote is said to be skipped already
			if (!array_all($rules, fn(string $rule) => isset($this->warnings[$rule]))) {
				$this->warnings["only $name"] = "$message; it is skipped.";
			}
		}
	}


	/**
	 * A name in fixRisky or reportOnly whose rules run nowhere, not even where an override turns them on, makes the entry
	 * a line that does nothing, which is worth a warning.
	 * @param  array<class-string<Rule>, ResolvedRule>  $active
	 * @param  array<class-string<Rule>, true>  $ofOverrides
	 * @throws ConfigurationException
	 */
	private function checkFixPolicies(Config $config, array $active, array $ofOverrides): void
	{
		foreach (self::listProfiles($config, array_keys($config->overrides)) as [$layer, $profile]) {
			foreach (['fixRisky' => $profile->fixRisky, 'reportOnly' => $profile->reportOnly] as $key => $entries) {
				foreach ($entries as $entry) {
					try {
						$expanded = $this->expandName($entry);
					} catch (ConfigurationException $e) {
						throw self::locate($e, $layer);
					}

					if (!array_filter($expanded->rules, fn(string $class) => isset($active[$class]) || isset($ofOverrides[$class]))) {
						$this->warnings["$key $expanded->name"] = ucfirst($expanded->format())
							. " is named in `$key` but runs nowhere; the entry does nothing.";
					}
				}
			}
		}
	}
}
