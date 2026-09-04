<?php declare(strict_types=1);

use DressCode\{Config, ConfigurableRule, ConfigurationException, NodeRule, Override, Preset, PresetInfo, Profile, Rule, RuleInfo, Stage};
use DressCode\Config\{PresetResolver, ResolvedRule, RuleRegistry};
use DressCode\Presets\Symfony;
use DressCode\Rules\Namespaces\NameNotationRule;
use Nette\Schema\{Expect, Processor, Schema};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/a', Stage::Formatting)]
final class RuleA extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/b', Stage::Formatting)]
final class RuleB extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/c', Stage::Formatting)]
final class RuleC extends NodeRule implements ConfigurableRule
{
	/** @var array<string, mixed> */
	public array $options = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure(['max' => Expect::int(3), 'names' => Expect::listOf('string')->default(['x'])]);
	}


	public function configure(array $options): void
	{
		$this->options = $options;
	}


	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/one-decision', Stage::Formatting, decision: 'shape')]
final class RuleOneDecision extends NodeRule implements ConfigurableRule
{
	/** @var array<string, mixed> */
	public array $options = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure(['shape' => Expect::anyOf('perLine', 'compact')->default('perLine')]);
	}


	public function configure(array $options): void
	{
		$this->options = $options;
	}


	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/d', Stage::Formatting)]
final class RuleD extends NodeRule
{
	public function __construct(
		public string $dependency,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/nested', Stage::Formatting)]
final class RuleNested extends NodeRule implements ConfigurableRule
{
	/** @var array<string, mixed> */
	public array $options = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'naming' => Expect::structure([
				'classes' => Expect::string('PascalCase'),
				'except' => Expect::listOf('string'),
			])->castTo('array'),
			'blank' => Expect::anyOf(Expect::int(), Expect::tuple([Expect::int(), Expect::int()->nullable()]))->default(1),
		]);
	}


	public function configure(array $options): void
	{
		$this->options = $options;
	}


	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[RuleInfo('test/future', Stage::Formatting, requires: ['php' => '>=8.4'])]
final class RuleFuture extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[PresetInfo('test/future-preset')]
final class FuturePreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [RuleA::class => true, RuleFuture::class => true]);
	}
}


#[RuleInfo('test/typed', Stage::Structure, requiresTypes: true)]
final class RuleTyped extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [];
	}
}


#[PresetInfo('test/typed-preset')]
final class TypedPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [RuleA::class => true, RuleTyped::class => true]);
	}
}


#[PresetInfo('test/types-preset')]
final class TypesPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(types: 'phpstan');
	}
}


#[PresetInfo('test/base')]
final class BasePreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [RuleA::class => true, RuleC::class => ['max' => 5], RuleB::class => true]);
	}
}


#[PresetInfo('test/child')]
final class ChildPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(presets: [BasePreset::class], rules: [RuleB::class => false, RuleC::class => ['names' => ['x']]]);
	}
}


#[PresetInfo('test/nested-preset')]
final class NestedPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [RuleNested::class => ['naming' => ['classes' => 'camelCase', 'except' => ['a']], 'blank' => [1, 2]]]);
	}
}


#[PresetInfo('test/off')]
final class OffPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(presets: [BasePreset::class], rules: [RuleC::class => false]);
	}
}


#[PresetInfo('test/styled')]
final class StyledPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(presets: [BasePreset::class], indent: 2, eol: 'LF');
	}
}


#[PresetInfo('test/broken')]
final class BrokenPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: ['test/none' => true]);
	}
}


#[PresetInfo('test/deciding')]
final class DecidingPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [RuleA::class => true], fixRisky: [RuleA::class]);
	}
}


#[PresetInfo('test/declaring')]
final class DeclaringPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(namespaces: ['functions' => ['Fw\Config\{service, param}'], 'constants' => ['Fw\VERSION']]);
	}
}


#[PresetInfo('test/bad-declarations')]
final class BadDeclarationsPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(namespaces: ['functions' => ['strlen']]);
	}
}


/** @return list<Rule> */
function resolve(Config $config, string $php = '8.3', ?Profile $commandLine = null): array
{
	$resolver = new PresetResolver(new RuleRegistry);
	return $resolver->build($resolver->resolve($config, $php, commandLine: $commandLine));
}


/**
 * @param  list<Rule>  $rules
 * @return list<string>
 */
function names(array $rules): array
{
	return array_map(fn(Rule $rule) => RuleInfo::of($rule)->name, $rules);
}


test('parents first, the child overrides the keys it names, order of the first mention', function () {
	// the base sets max, the child names only names: what the child does not say the base keeps
	$rules = resolve(new Config(presets: [ChildPreset::class]));
	Assert::same(['test/a', 'test/c'], names($rules));
	assert($rules[1] instanceof RuleC);
	Assert::equal(['max' => 5, 'names' => ['x']], $rules[1]->options);
});


test('the configuration overrides the presets', function () {
	$rules = resolve(new Config(presets: [ChildPreset::class], rules: ['test/b' => true, 'test/a' => false, RuleD::class => fn() => new RuleD('dep')]));
	Assert::same(['test/c', 'test/b', 'test/d'], names($rules));
	assert($rules[2] instanceof RuleD);
	Assert::same('dep', $rules[2]->dependency);
});


test('a rule that is one decision takes its value directly', function () {
	$rules = resolve(new Config(rules: [RuleOneDecision::class => 'compact']));
	assert($rules[0] instanceof RuleOneDecision);
	Assert::same(['shape' => 'compact'], $rules[0]->options);

	// the layers meet as they would in the long notation, whichever of the two each is written in
	$rules = resolve(new Config(rules: [RuleOneDecision::class => 'compact']), commandLine: new Profile(rules: [RuleOneDecision::class => ['shape' => 'perLine']]));
	assert($rules[0] instanceof RuleOneDecision);
	Assert::same(['shape' => 'perLine'], $rules[0]->options);

	// a rule that is more than one decision has nowhere to put the value
	Assert::exception(
		fn() => resolve(new Config(rules: [RuleC::class => 'compact'])),
		ConfigurationException::class,
		'Rule test/c takes no bare value, which the configuration gives it; write the options it has.',
	);
});


test('a list option replaces its default instead of being merged with it', function () {
	$rules = resolve(new Config(rules: [RuleC::class => ['names' => ['y']]]));
	assert($rules[0] instanceof RuleC);
	Assert::equal(['max' => 3, 'names' => ['y']], $rules[0]->options);
});


test('a map merges by key, a list and a scalar replace, and turning the rule off starts over', function () {
	$options = function (Config $config, ?Profile $commandLine = null): array {
		foreach (resolve($config, commandLine: $commandLine) as $rule) {
			if ($rule instanceof RuleC) {
				return $rule->options;
			}
		}

		return [];
	};

	// a later layer changes what it names and leaves the rest of the layer below it alone
	Assert::equal(
		['max' => 7, 'names' => ['x']],
		$options(new Config(presets: [BasePreset::class], rules: [RuleC::class => ['max' => 7]])),
	);

	// a list of the project replaces the list of the preset instead of extending it
	Assert::equal(
		['max' => 5, 'names' => ['y']],
		$options(new Config(presets: [BasePreset::class], rules: [RuleC::class => ['names' => ['y']]])),
	);
	Assert::equal(
		['max' => 5, 'names' => []],
		$options(new Config(presets: [BasePreset::class], rules: [RuleC::class => ['names' => []]])),
	);

	// two layers naming the same key: the later one wins
	Assert::equal(
		['max' => 9, 'names' => ['x']],
		$options(new Config(presets: [BasePreset::class], rules: [RuleC::class => ['max' => 8]]), new Profile(rules: [RuleC::class => ['max' => 9]])),
	);

	// turning the rule off drops what was said before it, so what follows starts from the defaults
	Assert::equal(
		['max' => 3, 'names' => ['z']],
		$options(new Config(presets: [OffPreset::class], rules: [RuleC::class => ['names' => ['z']]])),
	);
	Assert::same([], $options(new Config(presets: [OffPreset::class])));
});


test('a nested map merges by key and a tuple of an union replaces', function () {
	$options = function (Config $config): array {
		$rules = resolve($config);
		assert($rules[0] instanceof RuleNested);
		return $rules[0]->options;
	};

	Assert::equal(
		['naming' => ['classes' => 'PascalCase', 'except' => ['a']], 'blank' => [1, 2]],
		$options(new Config(rules: [RuleNested::class => ['naming' => ['except' => ['a']], 'blank' => [1, 2]]])),
	);

	// the map of the second layer meets the map of the first key by key, the tuple replaces
	Assert::equal(
		['naming' => ['classes' => 'camelCase', 'except' => ['b']], 'blank' => [0, null]],
		$options(new Config(presets: [NestedPreset::class], rules: [RuleNested::class => ['naming' => ['except' => ['b']], 'blank' => [0, null]]])),
	);

	// an int and a tuple are the two shapes of one option, and neither is merged with the other
	Assert::equal(
		['naming' => ['classes' => 'camelCase', 'except' => ['a']], 'blank' => 4],
		$options(new Config(presets: [NestedPreset::class], rules: [RuleNested::class => ['blank' => 4]])),
	);

	// and the other way round, a tuple over a number
	Assert::equal(
		['naming' => ['classes' => 'PascalCase', 'except' => []], 'blank' => [2, 3]],
		$options(new Config(rules: [RuleNested::class => ['blank' => [2, 3]]])),
	);
});


test('a rule of a construct the target version has not got is left out', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$resolve = fn(Config $config, string $php) => names($resolver->build($resolver->resolve($config, $php)));

	Assert::same(['test/a', 'test/future'], $resolve(new Config(presets: [FuturePreset::class]), '8.4'));
	Assert::same(['test/a'], $resolve(new Config(presets: [FuturePreset::class]), '8.3'));
	Assert::same([], $resolver->getWarnings()); // coming from a preset it is business as usual

	Assert::same([], $resolve(new Config(rules: [RuleFuture::class => true]), '8.3'));
	Assert::same(['Rule test/future needs PHP 8.4, the target is 8.3; skipped.'], $resolver->getWarnings());

	// what the result cache keys on is what really runs, and a rule that does not says why
	$resolved = $resolver->resolve(new Config(presets: [FuturePreset::class]), '8.3');
	Assert::same(['test/a'], array_keys($resolved->toArray()['rules']));
	$future = $resolved->getRule('test/future');
	Assert::type(ResolvedRule::class, $future);
	Assert::same('it needs PHP 8.4 and the target is 8.3', $future->inactive);
	Assert::same('test/future-preset', $future->getSource());
	Assert::same(['8.3', "\t", 'majority'], [$resolved->phpVersion, $resolved->indent, $resolved->eol]);
});


test('a rule that needs the types of the code runs only where the configuration gives them', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$resolve = fn(Config $config) => names($resolver->build($resolver->resolve($config, '8.3')));

	// coming from a preset it is left out in silence, whatever the project has
	Assert::same(['test/a'], $resolve(new Config(presets: [TypedPreset::class])));
	Assert::same([], $resolver->getWarnings());
	Assert::same(['test/a', 'test/typed'], $resolve(new Config(presets: [TypedPreset::class], types: 'phpstan')));
	Assert::same(['test/a'], $resolve(new Config(presets: [TypedPreset::class], rules: [RuleTyped::class => false], types: 'phpstan')));

	$resolved = $resolver->resolve(new Config(presets: [TypedPreset::class]), '8.3');
	Assert::same('it needs the types of the code and the configuration sets no types', $resolved->getRule('test/typed')?->inactive);
	Assert::null($resolved->types);
	Assert::null($resolved->toArray()['types']);
	Assert::same('phpstan', $resolver->resolve(new Config(types: 'phpstan'), '8.3')->types);

	// the project naming it asked for what it cannot get
	Assert::exception(
		fn() => $resolve(new Config(rules: [RuleTyped::class => true])),
		ConfigurationException::class,
		"Rule test/typed needs the types of the code: set 'types: phpstan' in the configuration, with phpstan/phpstan installed in the project.",
	);
	Assert::same(['test/typed'], $resolve(new Config(rules: [RuleTyped::class => true], types: 'phpstan')));

	// a preset may not decide it
	Assert::exception(
		fn() => $resolve(new Config(presets: [TypesPreset::class])),
		ConfigurationException::class,
		'Preset %a% sets types, which is a decision of the project, not of a standard.',
	);
});


/**
 * Narrows a run to the names and returns the rules that run for a file matching the overrides.
 * @param  list<string>  $only
 * @param  list<int>  $overrides
 * @return list<string>
 */
function narrow(
	PresetResolver $resolver,
	Config $config,
	array $only,
	array $overrides = [],
	?Profile $commandLine = null,
): array
{
	return array_map(fn(ResolvedRule $rule) => $rule->name, $resolver->resolve($config, '8.3', $overrides, $commandLine, $only)->getActiveRules());
}


test('--only keeps what it names of what the configuration comes to, and enables nothing', function () {
	$resolver = new PresetResolver(new RuleRegistry);

	// a rule by its name or its class, with the options it has without --only
	Assert::same(['test/c'], narrow($resolver, new Config(presets: [ChildPreset::class]), ['test/c']));
	Assert::same(['test/c'], narrow($resolver, new Config(presets: [ChildPreset::class]), [RuleC::class]));
	$narrowed = $resolver->resolve(new Config(presets: [ChildPreset::class]), '8.3', only: ['test/c']);
	Assert::same(['max' => 5, 'names' => ['x']], $narrowed->getRule('test/c')?->options);
	Assert::same('the run is narrowed to other rules', $narrowed->getRule('test/a')?->inactive);

	// a name without a vendor is the built-in one
	Assert::same(['dresscode/string-quotes'], narrow($resolver, new Config(presets: ['nette']), ['string-quotes']));

	// a preset stands for every rule it and its parents mention, and not for what the configuration added
	$config = new Config(presets: [ChildPreset::class], rules: [RuleNested::class => true]);
	Assert::same(['test/a', 'test/c'], narrow($resolver, $config, [ChildPreset::class]));
	Assert::same(['test/a', 'test/c'], narrow($resolver, $config, ['test/base']));
	Assert::same(['test/a', 'test/c', 'test/nested'], narrow($resolver, $config, ['test/base', 'test/nested']));

	// the command line is a layer below it: what --rule turned on, --only may keep
	$enabled = new Profile(rules: ['test/b' => true]);
	Assert::same(['test/b'], narrow($resolver, $config, ['test/b'], commandLine: $enabled));
	Assert::same(['test/a'], narrow($resolver, $config, ['test/a'], commandLine: $enabled));

	// a rule left out is not a rule skipped for its version, and says nothing
	$resolver->resolve(new Config(rules: [RuleFuture::class => true, RuleA::class => true]), '8.3', only: ['test/a']);
	Assert::same(['Rule test/future needs PHP 8.4, the target is 8.3; skipped.'], $resolver->getWarnings());

	// a rule only an override enables runs where the override applies
	$overridden = new Config(presets: [ChildPreset::class], overrides: [new Override(['tests'], rules: ['test/nested' => true])]);
	Assert::same([], narrow($resolver, $overridden, ['test/nested']));
	Assert::same(['test/nested'], narrow($resolver, $overridden, ['test/nested'], [0]));
});


test('a name of --only that lets in nothing that runs is an error, not an empty run', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	Assert::exception(
		fn() => narrow($resolver, new Config(presets: [ChildPreset::class]), ['test/b']),
		ConfigurationException::class,
		'Rule test/b the run is narrowed to does not run: turned off by test/child. Turn it on with --rule test/b=on.',
	);
	Assert::exception(
		fn() => narrow($resolver, new Config(presets: [ChildPreset::class]), [RuleNested::class]),
		ConfigurationException::class,
		'Rule test/nested the run is narrowed to does not run: no preset or rule of the configuration mentions it. Turn it on with --rule test/nested=on.',
	);
	Assert::exception(
		fn() => narrow($resolver, new Config(rules: [RuleFuture::class => true]), ['test/future']),
		ConfigurationException::class,
		'Rule test/future the run is narrowed to does not run: it needs PHP 8.4 and the target is 8.3.',
	);
	Assert::exception(
		fn() => narrow($resolver, new Config(presets: [ChildPreset::class]), [NestedPreset::class]),
		ConfigurationException::class,
		'Preset test/nested-preset the run is narrowed to has no rule that runs here.',
	);
	Assert::exception(
		fn() => narrow($resolver, new Config(presets: [ChildPreset::class]), ['test/basee']),
		ConfigurationException::class,
		"Unknown rule or preset 'test/basee'. Did you mean 'test/base'?",
	);
});


test('a configuration without a preset', function () {
	Assert::same(['test/c', 'test/a'], names(resolve(new Config(rules: [RuleC::class => true, RuleA::class => true]))));
	Assert::same([], resolve(new Config));
});


test('an override lays a profile of its own over the configuration, its presets included', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$config = new Config(
		presets: [BasePreset::class],
		rules: [RuleC::class => ['max' => 7]],
		nameResolution: 'certain',
		fixRisky: [RuleA::class],
		overrides: [
			new Override(['tests'], presets: [ChildPreset::class, NestedPreset::class, StyledPreset::class], nameResolution: 'uncertain', warnings: [RuleA::class]),
		],
	);
	$base = $resolver->resolve($config, '8.3');
	$tests = $resolver->resolve($config, '8.3', [0]);

	// a preset of the override lies above the configuration, and one the configuration already has is not laid again
	Assert::same(['test/base'], $base->presets);
	Assert::same(['test/base', 'test/child', 'test/nested-preset', 'test/styled'], $tests->presets);
	Assert::same(['test/a', 'test/c', 'test/b', 'dresscode/no-unlisted-namespaced-declaration'], names($resolver->build($base)));
	Assert::same(['test/a', 'test/c', 'test/nested'], names($resolver->build($tests)));
	Assert::same(['max' => 7, 'names' => ['x']], $tests->getRule('test/c')?->options);
	Assert::same('turned off by test/child', $tests->getRule('test/b')?->inactive);
	Assert::same([["\t", 'majority'], ['  ', "\n"]], [[$base->indent, $base->eol], [$tests->indent, $tests->eol]]);

	// a certain resolution turns on the guard of its lists, and a file that ends up uncertain has none to guard
	Assert::same(['certain', 'uncertain'], [$base->nameResolution, $tests->nameResolution]);
	Assert::same('no preset or rule of the configuration mentions it', $tests->getRule('dresscode/no-unlisted-namespaced-declaration')?->inactive);

	// a list adds up: what the configuration accepts holds under the override, which adds what it says
	Assert::same([true, false], [$base->getRule('test/a')?->fixRisky, $base->getRule('test/a')?->warning]);
	Assert::same([true, true], [$tests->getRule('test/a')?->fixRisky, $tests->getRule('test/a')?->warning]);
});


test('the command line lies over the overrides', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$config = new Config(rules: [RuleA::class => true], overrides: [new Override(['tests'], rules: [RuleA::class => false])]);
	Assert::same('turned off by the override for tests', $resolver->resolve($config, '8.3', [0])->getRule('test/a')?->inactive);
	$rule = $resolver->resolve($config, '8.3', [0], new Profile(rules: [RuleA::class => true]))->getRule('test/a');
	Assert::true($rule?->isActive());
	Assert::same('the command line', $rule->getSource());
});


test('the version of PHP a profile says is the target of its files, raised to the oldest one DressCode fixes code for', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$config = new Config(rules: [RuleFuture::class => true], overrides: [new Override(['legacy'], php: '8.3'), new Override(['ancient'], php: '7.4')]);
	Assert::true($resolver->resolve($config, '8.4')->getRule('test/future')?->isActive());
	Assert::same([], $resolver->getWarnings());

	$legacy = $resolver->resolve($config, '8.4', [0]);
	Assert::same('8.3', $legacy->phpVersion);
	Assert::same('it needs PHP 8.4 and the target is 8.3', $legacy->getRule('test/future')?->inactive);

	Assert::same('8.0', $resolver->resolve($config, '8.4', [1])->phpVersion);
	Assert::contains('The target PHP 7.4 is older than PHP 8.0, the oldest DressCode fixes code for;', implode("\n", $resolver->getWarnings()));
});


test('what the namespaces declare adds up over the layers, and only the configuration makes it certain', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	// one symbol spelled twice is listed once, as PHP reads the letter case of a function and of a namespace
	$uncertain = $resolver->resolve(
		new Config(presets: [DeclaringPreset::class], namespaces: ['functions' => ['App\helper', 'fw\config\SERVICE'], 'constants' => ['fw\VERSION', 'Fw\version']]),
		Config::DefaultPhpVersion,
	);
	Assert::same(
		[
			'Fw\Config\service' => 'test/declaring',
			'Fw\Config\param' => 'test/declaring',
			'App\helper' => 'the configuration',
		],
		$uncertain->namespacedFunctions,
	);
	Assert::same(['Fw\VERSION' => 'test/declaring', 'Fw\version' => 'the configuration'], $uncertain->namespacedConstants);
	Assert::same('uncertain', $uncertain->nameResolution);
	Assert::false($uncertain->toNamespacedSymbols()->complete);
	Assert::true($uncertain->toNamespacedSymbols()->hasFunction('App\helper'));

	$certain = $resolver->resolve(new Config(presets: [DeclaringPreset::class], nameResolution: 'certain'), Config::DefaultPhpVersion);
	Assert::true($certain->toNamespacedSymbols()->complete);
	Assert::true($certain->toNamespacedSymbols()->hasConstant('Fw\VERSION'));
	Assert::notSame($uncertain->toArray(), $certain->toArray());

	// a certain resolution turns on the guard of its lists, which the rules of the configuration may turn off
	$guard = 'dresscode/no-unlisted-namespaced-declaration';
	$rule = $certain->getRule($guard);
	Assert::notNull($rule);
	Assert::true($rule->isActive());
	Assert::same('nameResolution: certain', $rule->getSource());
	Assert::false($uncertain->getRule($guard)?->isActive());
	$kept = $resolver->resolve(new Config(rules: [$guard => false], nameResolution: 'certain'), Config::DefaultPhpVersion);
	Assert::false($kept->getRule($guard)?->isActive());

	// an override adds to the lists for its files
	$overridden = new Config(namespaces: ['functions' => ['App\helper']], overrides: [new Override(['tests'], namespaces: ['functions' => ['App\Tests\fixture']])]);
	Assert::same(
		['App\helper' => 'the configuration', 'App\Tests\fixture' => 'the override for tests'],
		$resolver->resolve($overridden, Config::DefaultPhpVersion, [0])->namespacedFunctions,
	);

	Assert::exception(
		fn() => $resolver->resolve(new Config(presets: [BadDeclarationsPreset::class]), Config::DefaultPhpVersion),
		ConfigurationException::class,
		"'strlen' is in no namespace, and a global function needs no listing. (in preset test/bad-declarations)",
	);
});


test('a rule whose options decide nothing says so through its schema, and name-notation refuses a value it has not', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$resolver->resolve(new Config(rules: [
		'dresscode/name-notation' => true,
		'dresscode/name-fallback' => true, // no key given, so it stays the only rule of its group that decides nothing
		'dresscode/name-casing' => ['ignorePatterns' => ['~^x~']], // a pattern of what not to report, and still no case to report
		'dresscode/forbidden-functions' => true, // a list the packages of the project may fill, so an empty one is no mistake
	]), '8.4');
	$warnings = $resolver->getWarnings();
	sort($warnings);
	Assert::same([
		'Rule dresscode/name-casing: No kind of name is given a case, so nothing is reported.',
		'Rule dresscode/name-fallback: No key such as functions or optimizedFunctions is given, so every name stays as it is.',
		'Rule dresscode/name-notation: No key such as classes or globalFunctions is given, so every name stays as it is.',
	], $warnings);

	$resolver = new PresetResolver(new RuleRegistry);
	$resolver->resolve(new Config(rules: ['dresscode/name-fallback' => ['optimizedFunctions' => 'qualified']]), '8.4');
	Assert::same([], $resolver->getWarnings());

	// whether a name stands bare is no shape of name-notation any more
	Assert::exception(
		fn() => $resolver->resolve(new Config(rules: ['dresscode/name-notation' => ['globalFunctions' => 'bare']]), '8.4'),
		ConfigurationException::class,
		'%a%globalFunctions%a%',
	);
	Assert::exception(
		fn() => $resolver->resolve(new Config(rules: ['dresscode/name-notation' => ['optimizedFunctions' => 'import']]), '8.4'),
		ConfigurationException::class,
		'%a%optimizedFunctions%a%',
	);

	// a map of the configuration merges with the plain value of the preset as with its pattern *, and a plain value reads back plain
	$merged = $resolver->resolve(new Config(presets: [Symfony::class], rules: ['dresscode/name-notation' => ['globalFunctions' => ['strlen' => 'import']]]), '8.4');
	$rule = $merged->getRule('dresscode/name-notation');
	Assert::notNull($rule);
	Assert::same(['*' => 'backslash', 'strlen' => 'import'], $rule->options['globalFunctions']);
	Assert::same('backslash', $rule->options['globalClasses']);

	// while a plain value over a map replaces it with its names
	$options = (new Processor)->processMultiple(NameNotationRule::getOptionsSchema(), [
		['globalFunctions' => ['*' => 'import', 'strlen' => 'backslash']],
		['globalFunctions' => 'backslash'],
	]);
	Assert::same('backslash', ((array) $options)['globalFunctions']);
});


test('the style is the last one a layer says, else a tab and the line ending each file mostly has', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$style = function (Config $config) use ($resolver): array {
		$resolved = $resolver->resolve($config, '8.3');
		return [$resolved->indent, $resolved->eol];
	};
	Assert::same(["\t", 'majority'], $style(new Config));
	Assert::same(["\t", 'majority'], $style(new Config(presets: [ChildPreset::class])));
	Assert::same(['  ', "\n"], $style(new Config(presets: [StyledPreset::class])));
	Assert::same(['  ', "\n"], $style(new Config(presets: [StyledPreset::class, ChildPreset::class])));
	Assert::same(['    ', "\n"], $style(new Config(presets: [StyledPreset::class], indent: 4)));
	Assert::same(['  ', "\r\n"], $style(new Config(presets: [StyledPreset::class], eol: 'CRLF')));
	Assert::same(['  ', 'majority'], $style(new Config(presets: [StyledPreset::class], eol: 'majority')));
	Assert::same(['  ', PHP_EOL], $style(new Config(presets: [StyledPreset::class], eol: 'platform')));
});


test('a group turns on every rule that carries it, under the rules of its own profile', function () {
	// the group of the preset lies under the preset, the group of the configuration under its rules
	$rules = resolve(new Config(groups: ['cleanup'], rules: ['dresscode/unused-imports' => false]));
	Assert::contains('dresscode/useless-else', names($rules));
	Assert::notContains('dresscode/unused-imports', names($rules));
	Assert::notContains('dresscode/braces-position', names($rules)); // no group: the standard chooses it

	// a group names no rule, so a rule of it that cannot run is left out in silence, as a preset's is
	$resolver = new PresetResolver(new RuleRegistry);
	$resolved = $resolver->resolve(new Config(groups: ['modernization']), '8.3');
	$byName = array_column($resolved->rules, null, 'name');
	Assert::same('it needs PHP 8.5 and the target is 8.3', $byName['dresscode/pipe-operator']->inactive);
	Assert::same([], $resolver->getWarnings());
	Assert::same(['modernization'], $resolved->groups);

	// the group of the command line lies over the configuration, and the value of a rule is where it was said
	$rules = resolve(new Config(rules: ['dresscode/useless-else' => false]), commandLine: new Profile(groups: ['cleanup']));
	Assert::contains('dresscode/useless-else', names($rules));
});


test('a group is one of the groups, and the name of one narrows the run to its rules', function () {
	Assert::exception(
		fn() => new Config(groups: ['cleanups']),
		InvalidArgumentException::class,
		"Unknown group 'cleanups'; the groups are cleanup, modernization, types, deprecations, correctness, optimized-calls.",
	);

	$resolver = new PresetResolver(new RuleRegistry);
	$resolved = $resolver->resolve(new Config(groups: ['cleanup', 'types']), '8.3', only: ['types']);
	$active = array_map(fn(ResolvedRule $rule) => $rule->name, $resolved->getActiveRules());
	Assert::contains('dresscode/phpdoc-canonical-types', $active);
	Assert::notContains('dresscode/useless-else', $active);

	Assert::exception(
		fn() => $resolver->resolve(new Config(groups: ['cleanup']), '8.3', only: ['types']),
		ConfigurationException::class,
		'Group types the run is narrowed to has no rule that runs here.',
	);
});


test('errors', function () {
	Assert::exception(fn() => resolve(new Config(rules: ['test/none' => true])), ConfigurationException::class, "Unknown rule 'test/none'.");
	Assert::exception(fn() => resolve(new Config(presets: [BrokenPreset::class])), ConfigurationException::class, "Unknown rule 'test/none'. (in preset test/broken)");
	Assert::exception(fn() => resolve(new Config(presets: [DecidingPreset::class])), ConfigurationException::class, 'Preset test/deciding sets fixRisky, which is a decision of the project, not of a standard.');
	Assert::exception(fn() => resolve(new Config(overrides: [new Override(['tests'], presets: ['test/nope'])])), ConfigurationException::class, "Unknown preset 'test/nope'. (in the override for tests)");
	Assert::exception(fn() => resolve(new Config(overrides: [new Override(['tests'], fixRisky: ['test/nope'])])), ConfigurationException::class, "Unknown rule 'test/nope'. (in the override for tests)");
	Assert::exception(
		fn() => new PresetResolver(new RuleRegistry)->resolve(new Config(overrides: [new Override(['tests'], warnings: ['test/nope'])]), '8.3', [0]),
		ConfigurationException::class,
		"Unknown rule 'test/nope'. (in the override for tests)",
	);
	Assert::exception(fn() => resolve(new Config(rules: [RuleA::class => ['x' => 1]])), ConfigurationException::class, 'Rule test/a has no options.');
	// the message names the layer that set the options, because that is where the reader has to go
	Assert::exception(fn() => resolve(new Config(rules: [RuleC::class => ['max' => 'no']])), ConfigurationException::class, "Invalid options of rule test/c set by the configuration: The item 'max' expects to be int, 'no' given.");
	Assert::exception(fn() => resolve(new Config(presets: [BasePreset::class], rules: [RuleC::class => ['maxx' => 1]])), ConfigurationException::class, "Invalid options of rule test/c set by test/base and the configuration: Unexpected item 'maxx', did you mean 'max'?");
	Assert::exception(fn() => resolve(new Config(rules: [RuleD::class => fn() => new RuleA])), ConfigurationException::class, 'The factory of rule test/d returned RuleA instead of RuleD.');
});
