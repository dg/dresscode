<?php declare(strict_types=1);

namespace DressCode\Config;

use DressCode\Config;
use DressCode\ConfigurableRule;
use DressCode\ConfigurationException;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rule;
use DressCode\RuleInfo;
use Nette\Schema\Elements\ArrayType;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Helpers;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use function count, is_array, is_int, is_string;


/**
 * Composes the presets of a configuration with its own rules into the ordered list of rule instances:
 * parents first, a later mention overrides the whole entry, the order is that of the first mention.
 * @internal
 */
final class PresetResolver
{
	/** @var array<string, string> */
	private array $warnings = [];


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
	 * @return list<Rule>
	 * @throws ConfigurationException
	 */
	public function resolve(Config $config, PresetContext $context): array
	{
		return $this->build($this->resolveConfig($config, $context));
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
	 * The configuration as data: what every rule ends up with, where it came from, and why a rule that
	 * does not run does not. The run, the result cache and whoever prints the configuration read this one
	 * result, so that none of them can say something the others do not.
	 * @param  list<int>  $blocks  indexes of the `for` blocks that match the file this is resolved for
	 * @throws ConfigurationException
	 */
	public function resolveConfig(Config $config, PresetContext $context, array $blocks = []): ResolvedConfig
	{
		$presets = $this->listPresets($config);
		/** @var array<class-string<Rule>, list<array{string, mixed}>> $layers */
		$layers = [];
		foreach ($presets as $class) {
			$name = PresetInfo::of($class)->name;
			foreach ((new $class)->getRules($context) as $rule => $value) {
				try {
					$layers[$this->registry->resolveRule($rule)][] = [$name, self::normalize($value)];
				} catch (ConfigurationException $e) {
					throw new ConfigurationException("{$e->getMessage()} (in preset $name)", previous: $e);
				}
			}
		}

		$explicit = [];
		foreach ($config->getRules() as $rule => $value) {
			$class = $this->registry->resolveRule($rule);
			$layers[$class][] = ['the configuration', self::normalize($value)];
			$explicit[$class] = true;
		}

		// the blocks come last and in the order they were written, so that a later one has the last word
		$all = $config->getBlocks();
		foreach ($blocks as $index) {
			[$files, $rules] = $all[$index];
			$source = 'for ' . implode(', ', $files);
			foreach ($rules as $rule => $value) {
				$class = $this->registry->resolveRule($rule);
				$layers[$class][] = [$source, self::normalize($value)];
				$explicit[$class] = true;
			}
		}

		// --only filters what the rest comes to, so it takes a rule away and never enables one
		$narrowed = $this->resolveOnly($config, $context);
		$only = $narrowed === null ? null : array_fill_keys(array_merge(...array_column($narrowed, 1)), true);
		$rules = $inactive = [];
		foreach ($layers as $class => $ruleLayers) {
			$resolved = $this->resolveRule($class, $ruleLayers, $context->getPhpVersion(), isset($explicit[$class]), $only === null || isset($only[$class]));
			$resolved->isActive() ? $rules[$class] = $resolved : $inactive[$class] = $resolved;
		}

		foreach ($this->registry->getRules() as $name => $class) {
			if (!isset($layers[$class])) {
				$inactive[$class] = new ResolvedRule($name, $class, [], [], inactive: 'no preset or rule of the configuration mentions it');
			}
		}

		if ($narrowed !== null && $blocks === []) {
			self::checkOnly($narrowed, $rules, $inactive, $this->findRulesOfBlocks($config));
		}

		[$indent, $eol] = $this->resolveStyle($config);
		return new ResolvedConfig(
			[...array_values($rules), ...array_values($inactive)],
			$indent,
			$eol,
			$context->getPhpVersion(),
			array_map(fn(string $class) => PresetInfo::of($class)->name, $presets),
		);
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
	 * The rules a run narrowed by --only keeps, per name it was given: a rule for itself, a preset for every
	 * rule it and its parents mention.
	 * @return ?list<array{class-string<Rule>|class-string<Preset>, list<class-string<Rule>>}>  what a name names and the rules it lets in; null when the run is not narrowed
	 * @throws ConfigurationException
	 */
	private function resolveOnly(Config $config, PresetContext $context): ?array
	{
		$names = $config->getOnly();
		if (!$names) {
			return null;
		}

		$narrowed = [];
		foreach ($names as $name) {
			$class = $this->registry->resolveRuleOrPreset($name);
			if (!is_a($class, Preset::class, allow_string: true)) {
				$narrowed[] = [$class, [$class]];
				continue;
			}

			$presets = $visited = $rules = [];
			$this->collectPreset($class, $presets, $visited);
			foreach ($presets as $preset) {
				foreach (array_keys((new $preset)->getRules($context)) as $rule) {
					$rules[] = $this->registry->resolveRule($rule);
				}
			}

			$narrowed[] = [$class, $rules];
		}

		return $narrowed;
	}


	/**
	 * A name of --only that lets in nothing that runs would make a run that checks nothing and says it is
	 * clean; a rule that runs only where a `for` block enables it is not such a name.
	 * @param  list<array{class-string<Rule>|class-string<Preset>, list<class-string<Rule>>}>  $narrowed
	 * @param  array<class-string<Rule>, ResolvedRule>  $active
	 * @param  array<class-string<Rule>, ResolvedRule>  $inactive
	 * @param  array<class-string<Rule>, true>  $ofBlocks
	 * @throws ConfigurationException
	 */
	private static function checkOnly(array $narrowed, array $active, array $inactive, array $ofBlocks): void
	{
		foreach ($narrowed as [$class, $rules]) {
			if (array_filter($rules, fn(string $rule) => isset($active[$rule]) || isset($ofBlocks[$rule]))) {
				continue;
			} elseif (is_subclass_of($class, Preset::class)) {
				throw new ConfigurationException('Preset ' . PresetInfo::of($class)->name . ' named by --only has no rule that runs here.');
			}

			$rule = $inactive[$class];
			throw new ConfigurationException(
				"Rule $rule->name named by --only does not run: $rule->inactive."
				. (str_starts_with((string) $rule->inactive, 'it needs PHP') ? '' : " Turn it on with --rule $rule->name=on."),
			);
		}
	}


	/**
	 * The rules some `for` block of the configuration enables, whichever file it applies to.
	 * @return array<class-string<Rule>, true>
	 * @throws ConfigurationException
	 */
	private function findRulesOfBlocks(Config $config): array
	{
		$rules = [];
		foreach ($config->getBlocks() as [, $blockRules]) {
			foreach ($blockRules as $rule => $value) {
				if (self::normalize($value) !== false) {
					$rules[$this->registry->resolveRule($rule)] = true;
				}
			}
		}

		return $rules;
	}


	/**
	 * @param  class-string<Rule>  $class
	 * @param  list<array{string, mixed}>  $layers
	 * @param  bool  $kept  whether --only keeps the rule, or the run is not narrowed
	 * @throws ConfigurationException
	 */
	private function resolveRule(
		string $class,
		array $layers,
		string $phpVersion,
		bool $explicit,
		bool $kept = true,
	): ResolvedRule
	{
		$info = RuleInfo::of($class);
		$last = $layers[count($layers) - 1][1];
		$tooNew = $info->minPhpVersion !== null && version_compare($phpVersion, $info->minPhpVersion, '<');
		$inactive = match (true) {
			$last === false => 'turned off by ' . $layers[count($layers) - 1][0],
			$tooNew => "it needs PHP $info->minPhpVersion and the target is $phpVersion",
			!$kept => 'left out by --only',
			default => null,
		};
		if ($tooNew && $last !== false && $explicit) {
			$this->warnings[$info->name] = "Rule $info->name needs PHP $info->minPhpVersion, the target is $phpVersion; skipped.";
		}

		return new ResolvedRule(
			$info->name,
			$class,
			$inactive === null ? self::validateOptions($class, $info->name, self::stack($layers, $info), self::describeSources($layers)) : [],
			$layers,
			$inactive,
			$last instanceof \Closure ? $last : null,
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
	 * Indentation unit and line ending of a run: the configuration wins, then the last preset declaring
	 * them, then a tab and the majority line ending of each file. 'platform' is answered here, so that
	 * the rest of the run, the result cache included, sees the line ending it stands for.
	 * @return array{string, "\n"|"\r\n"|'majority'}
	 * @throws ConfigurationException
	 */
	public function resolveStyle(Config $config): array
	{
		$indent = $eol = null;
		foreach ($this->listPresets($config) as $class) {
			$info = PresetInfo::of($class);
			$indent = $info->indent ?? $indent;
			$eol = $info->eol ?? $eol;
		}

		$eol = match ($config->getEol() ?? $eol ?? 'majority') {
			'LF' => "\n",
			'CRLF' => "\r\n",
			'platform' => PHP_EOL === "\r\n" ? "\r\n" : "\n",
			'majority' => 'majority',
			default => throw new ConfigurationException("The line ending must be 'LF', 'CRLF', 'majority' or 'platform'."),
		};

		$indent = $config->getIndent() ?? $indent ?? 'tab';
		return [
			match (true) {
				$indent === 'tab' => "\t",
				is_int($indent) && $indent >= 1 => str_repeat(' ', $indent),
				default => throw new ConfigurationException("The indentation must be a number of spaces or 'tab'."),
			},
			$eol,
		];
	}


	/**
	 * Presets of the configuration with their parents, parents first, each once.
	 * @return list<class-string<Preset>>
	 * @throws ConfigurationException
	 */
	private function listPresets(Config $config): array
	{
		$list = $visited = [];
		foreach ($config->getPresets() as $preset) {
			$this->collectPreset($this->registry->resolvePreset($preset), $list, $visited);
		}

		return $list;
	}


	/**
	 * @param  class-string<Preset>  $class
	 * @param  list<class-string<Preset>>  $list
	 * @param  array<class-string<Preset>, true>  $visited
	 */
	private function collectPreset(string $class, array &$list, array &$visited): void
	{
		if (isset($visited[$class])) {
			return;
		}

		$visited[$class] = true;
		foreach ((new $class)->getParents() as $parent) {
			$this->collectPreset($this->registry->resolvePreset($parent), $list, $visited);
		}

		$list[] = $class;
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
			self::validateOptions($class, $name, self::stack([['the caller', $value]], $info)),
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
	 * the layer below it key by key and a list or a scalar replaces it.
	 * @param  class-string<Rule>  $class
	 * @param  list<array<string, mixed>>  $layers
	 * @param  string  $sources  who set them, for an error message
	 * @return array<string, mixed>
	 */
	private static function validateOptions(string $class, string $name, array $layers, string $sources = ''): array
	{
		if (!is_subclass_of($class, ConfigurableRule::class)) {
			return [];
		}

		$schema = $class::getOptionsSchema();
		if ($schema instanceof Structure) { // a list given replaces its default instead of extending it
			foreach ($schema->getShape() as $item) {
				if ($item instanceof ArrayType) {
					$item->mergeDefaults(false);
				}
			}
		}

		try {
			$normalized = (new Processor)->processMultiple($schema, $layers ?: [[]]);
		} catch (ValidationException $e) {
			throw new ConfigurationException(
				"Invalid options of rule $name" . ($sources === '' ? '' : " set by $sources") . ': '
				. implode(' ', $e->getMessages()),
				previous: $e,
			);
		}

		return (array) $normalized;
	}
}
