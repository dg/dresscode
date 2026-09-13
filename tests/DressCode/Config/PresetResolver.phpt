<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\ConfigurableRule;
use DressCode\ConfigurationException;
use DressCode\NodeRule;
use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
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


#[RuleInfo('test/future', Stage::Formatting, minPhpVersion: '8.4')]
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
	public function getRules(PresetContext $context): array
	{
		return [RuleA::class => true, RuleFuture::class => true];
	}


	public function getParents(): array
	{
		return [];
	}
}


#[PresetInfo('test/base')]
final class BasePreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [RuleA::class => true, RuleC::class => ['max' => 5], RuleB::class => true];
	}


	public function getParents(): array
	{
		return [];
	}
}


#[PresetInfo('test/child')]
final class ChildPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return version_compare($context->getPhpVersion(), '8.3', '>=')
			? [RuleB::class => false, RuleC::class => ['names' => ['x']]]
			: [RuleB::class => false];
	}


	public function getParents(): array
	{
		return [BasePreset::class];
	}
}


#[PresetInfo('test/nested-preset')]
final class NestedPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [RuleNested::class => ['naming' => ['classes' => 'camelCase', 'except' => ['a']], 'blank' => [1, 2]]];
	}


	public function getParents(): array
	{
		return [];
	}
}


#[PresetInfo('test/off')]
final class OffPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [RuleC::class => false];
	}


	public function getParents(): array
	{
		return [BasePreset::class];
	}
}


#[PresetInfo('test/styled', indent: 2, eol: 'LF')]
final class StyledPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [];
	}


	public function getParents(): array
	{
		return [BasePreset::class];
	}
}


#[PresetInfo('test/broken')]
final class BrokenPreset implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return ['test/none' => true];
	}


	public function getParents(): array
	{
		return [];
	}
}


/** @return list<Rule> */
function resolve(Config $config, string $php = '8.3'): array
{
	return new PresetResolver(new RuleRegistry)->resolve($config, new PresetContext($php));
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
	$rules = resolve(Config::create()->preset(ChildPreset::class));
	Assert::same(['test/a', 'test/c'], names($rules));
	assert($rules[1] instanceof RuleC);
	Assert::equal(['max' => 5, 'names' => ['x']], $rules[1]->options);

	$rules = resolve(Config::create()->preset(ChildPreset::class), php: '8.2');
	Assert::same(['test/a', 'test/c'], names($rules));
	assert($rules[1] instanceof RuleC);
	Assert::equal(['max' => 5, 'names' => ['x']], $rules[1]->options);
});


test('the configuration overrides the presets', function () {
	$rules = resolve(Config::create()->preset(ChildPreset::class)->enable('test/b')->disable('test/a')->enable(RuleD::class, fn() => new RuleD('dep')));
	Assert::same(['test/c', 'test/b', 'test/d'], names($rules));
	assert($rules[2] instanceof RuleD);
	Assert::same('dep', $rules[2]->dependency);
});


test('a rule that is one decision takes its value directly', function () {
	$rules = resolve(Config::create()->enable(RuleOneDecision::class, 'compact'));
	assert($rules[0] instanceof RuleOneDecision);
	Assert::same(['shape' => 'compact'], $rules[0]->options);

	// the layers meet as they would in the long notation, whichever of the two each is written in
	$rules = resolve(Config::create()->enable(RuleOneDecision::class, 'compact')->enable(RuleOneDecision::class, ['shape' => 'perLine']));
	assert($rules[0] instanceof RuleOneDecision);
	Assert::same(['shape' => 'perLine'], $rules[0]->options);

	// a rule that is more than one decision has nowhere to put the value
	Assert::exception(
		fn() => resolve(Config::create()->enable(RuleC::class, 'compact')),
		ConfigurationException::class,
		'Rule test/c takes no bare value, which the configuration gives it; write the options it has.',
	);
});


test('a list option replaces its default instead of being merged with it', function () {
	$rules = resolve(Config::create()->enable(RuleC::class, ['names' => ['y']]));
	assert($rules[0] instanceof RuleC);
	Assert::equal(['max' => 3, 'names' => ['y']], $rules[0]->options);
});


test('a map merges by key, a list and a scalar replace, and turning the rule off starts over', function () {
	$options = function (Config $config): array {
		foreach (resolve($config) as $rule) {
			if ($rule instanceof RuleC) {
				return $rule->options;
			}
		}

		return [];
	};

	// a later layer changes what it names and leaves the rest of the layer below it alone
	Assert::equal(
		['max' => 7, 'names' => ['x']],
		$options(Config::create()->preset(BasePreset::class)->enable(RuleC::class, ['max' => 7])),
	);

	// a list of the project replaces the list of the preset instead of extending it
	Assert::equal(
		['max' => 5, 'names' => ['y']],
		$options(Config::create()->preset(BasePreset::class)->enable(RuleC::class, ['names' => ['y']])),
	);
	Assert::equal(
		['max' => 5, 'names' => []],
		$options(Config::create()->preset(BasePreset::class)->enable(RuleC::class, ['names' => []])),
	);

	// two layers naming the same key: the later one wins
	Assert::equal(
		['max' => 9, 'names' => ['x']],
		$options(Config::create()->preset(BasePreset::class)->enable(RuleC::class, ['max' => 8])->enable(RuleC::class, ['max' => 9])),
	);

	// turning the rule off drops what was said before it, so what follows starts from the defaults
	Assert::equal(
		['max' => 3, 'names' => ['z']],
		$options(Config::create()->preset(OffPreset::class)->enable(RuleC::class, ['names' => ['z']])),
	);
	Assert::same([], $options(Config::create()->preset(OffPreset::class)));
});


test('a nested map merges by key and a tuple of an union replaces', function () {
	$options = function (Config $config): array {
		$rules = resolve($config);
		assert($rules[0] instanceof RuleNested);
		return $rules[0]->options;
	};

	Assert::equal(
		['naming' => ['classes' => 'PascalCase', 'except' => ['a']], 'blank' => [1, 2]],
		$options(Config::create()->enable(RuleNested::class, ['naming' => ['except' => ['a']], 'blank' => [1, 2]])),
	);

	// the map of the second layer meets the map of the first key by key, the tuple replaces
	Assert::equal(
		['naming' => ['classes' => 'camelCase', 'except' => ['b']], 'blank' => [0, null]],
		$options(Config::create()->preset(NestedPreset::class)->enable(RuleNested::class, ['naming' => ['except' => ['b']], 'blank' => [0, null]])),
	);

	// an int and a tuple are the two shapes of one option, and neither is merged with the other
	Assert::equal(
		['naming' => ['classes' => 'camelCase', 'except' => ['a']], 'blank' => 4],
		$options(Config::create()->preset(NestedPreset::class)->enable(RuleNested::class, ['blank' => 4])),
	);

	// and the other way round, a tuple over a number
	Assert::equal(
		['naming' => ['classes' => 'PascalCase', 'except' => []], 'blank' => [2, 3]],
		$options(Config::create()->enable(RuleNested::class, ['blank' => [2, 3]])),
	);
});


test('a rule of a construct the target version has not got is left out', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$resolve = fn(Config $config, string $php) => names($resolver->resolve($config, new PresetContext($php)));

	Assert::same(['test/a', 'test/future'], $resolve(Config::create()->preset(FuturePreset::class), '8.4'));
	Assert::same(['test/a'], $resolve(Config::create()->preset(FuturePreset::class), '8.3'));
	Assert::same([], $resolver->getWarnings()); // coming from a preset it is business as usual

	Assert::same([], $resolve(Config::create()->enable(RuleFuture::class), '8.3'));
	Assert::same(['Rule test/future needs PHP 8.4, the target is 8.3; skipped.'], $resolver->getWarnings());

	// what the result cache keys on is what really runs, and a rule that does not says why
	$resolved = $resolver->resolveConfig(Config::create()->preset(FuturePreset::class), new PresetContext('8.3'));
	Assert::same(['test/a'], array_keys($resolved->toArray()['rules']));
	$future = $resolved->getRule('test/future');
	Assert::type(DressCode\Config\ResolvedRule::class, $future);
	Assert::same('it needs PHP 8.4 and the target is 8.3', $future->inactive);
	Assert::same('test/future-preset', $future->getSource());
	Assert::same(['8.3', "\t", 'majority'], [$resolved->phpVersion, $resolved->indent, $resolved->eol]);
});


test('--only keeps what it names of what the configuration comes to, and enables nothing', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	$resolve = fn(Config $config) => $resolver->resolveConfig($config, new PresetContext('8.3'));
	$active = fn(Config $config, int ...$overrides) => array_keys($resolver->resolveConfig($config, new PresetContext('8.3'), array_values($overrides))->toArray()['rules']);

	// a rule by its name or its class, with the options it has without --only
	Assert::same(['test/c'], $active(Config::create()->preset(ChildPreset::class)->only(['test/c'])));
	Assert::same(['test/c'], $active(Config::create()->preset(ChildPreset::class)->only([RuleC::class])));
	Assert::same(['max' => 5, 'names' => ['x']], $resolve(Config::create()->preset(ChildPreset::class)->only(['test/c']))->getRule('test/c')?->options);
	Assert::same('left out by --only', $resolve(Config::create()->preset(ChildPreset::class)->only(['test/c']))->getRule('test/a')?->inactive);

	// a name without a vendor is the built-in one
	Assert::same(['dresscode/string-quotes'], $active(Config::create()->preset('nette')->only(['string-quotes'])));

	// a preset stands for every rule it and its parents mention, and not for what the configuration added
	$config = fn() => Config::create()->preset(ChildPreset::class)->enable(RuleNested::class);
	Assert::same(['test/a', 'test/c'], $active($config()->only([ChildPreset::class])));
	Assert::same(['test/a', 'test/c'], $active($config()->only(['test/base'])));
	Assert::same(['test/a', 'test/c', 'test/nested'], $active($config()->only(['test/base', 'test/nested'])));

	// the command line is a layer below it: what --rule turned on, --only may keep
	Assert::same(['test/b'], $active($config()->enable('test/b')->only(['test/b'])));
	Assert::same(['test/a'], $active($config()->enable('test/b')->only(['test/a'])));

	// a rule left out is not a rule skipped for its version, and says nothing
	$resolve(Config::create()->enable(RuleFuture::class)->enable(RuleA::class)->only(['test/a']));
	Assert::same(['Rule test/future needs PHP 8.4, the target is 8.3; skipped.'], $resolver->getWarnings());

	// a rule only an override enables runs where the override applies
	$overridden = Config::create()->preset(ChildPreset::class)->override(['tests'], ['test/nested' => true])->only(['test/nested']);
	Assert::same([], $active($overridden));
	Assert::same(['test/nested'], $active($overridden, 0));
});


test('a name of --only that lets in nothing that runs is an error, not an empty run', function () {
	$resolve = fn(Config $config) => new PresetResolver(new RuleRegistry)->resolveConfig($config, new PresetContext('8.3'));
	Assert::exception(
		fn() => $resolve(Config::create()->preset(ChildPreset::class)->only(['test/b'])),
		ConfigurationException::class,
		'Rule test/b named by --only does not run: turned off by test/child. Turn it on with --rule test/b=on.',
	);
	Assert::exception(
		fn() => $resolve(Config::create()->preset(ChildPreset::class)->only([RuleNested::class])),
		ConfigurationException::class,
		'Rule test/nested named by --only does not run: no preset or rule of the configuration mentions it. Turn it on with --rule test/nested=on.',
	);
	Assert::exception(
		fn() => $resolve(Config::create()->enable(RuleFuture::class)->only(['test/future'])),
		ConfigurationException::class,
		'Rule test/future named by --only does not run: it needs PHP 8.4 and the target is 8.3.',
	);
	Assert::exception(
		fn() => $resolve(Config::create()->preset(ChildPreset::class)->only([NestedPreset::class])),
		ConfigurationException::class,
		'Preset test/nested-preset named by --only has no rule that runs here.',
	);
	Assert::exception(
		fn() => $resolve(Config::create()->preset(ChildPreset::class)->only(['test/basee'])),
		ConfigurationException::class,
		"Unknown rule or preset 'test/basee'. Did you mean 'test/base'?",
	);
});


test('a configuration without a preset', function () {
	Assert::same(['test/c', 'test/a'], names(resolve(Config::create()->enable(RuleC::class)->enable(RuleA::class))));
	Assert::same([], resolve(Config::create()));
});


test('the style comes from the configuration, else from the last preset declaring one, else tab and majority', function () {
	$resolver = new PresetResolver(new RuleRegistry);
	Assert::same(["\t", 'majority'], $resolver->resolveStyle(Config::create()));
	Assert::same(["\t", 'majority'], $resolver->resolveStyle(Config::create()->preset(ChildPreset::class)));
	Assert::same(['  ', "\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)));
	Assert::same(['  ', "\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->preset(ChildPreset::class)));
	Assert::same(['    ', "\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->indent(4)));
	Assert::same(['  ', "\r\n"], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->eol('CRLF')));
	Assert::same(['  ', 'majority'], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->eol('majority')));
	Assert::same(['  ', PHP_EOL], $resolver->resolveStyle(Config::create()->preset(StyledPreset::class)->eol('platform')));
	Assert::exception(fn() => $resolver->resolveStyle(Config::create()->eol('unix')), ConfigurationException::class, "The line ending must be 'LF', 'CRLF', 'majority' or 'platform'.");
	Assert::exception(fn() => $resolver->resolveStyle(Config::create()->eol('lf')), ConfigurationException::class, "The line ending must be 'LF', 'CRLF', 'majority' or 'platform'.");
	Assert::exception(fn() => $resolver->resolveStyle(Config::create()->indent('spaces')), ConfigurationException::class, "The indentation must be a number of spaces or 'tab'.");
});


test('errors', function () {
	Assert::exception(fn() => resolve(Config::create()->enable('test/none')), ConfigurationException::class, "Unknown rule 'test/none'.");
	Assert::exception(fn() => resolve(Config::create()->preset(BrokenPreset::class)), ConfigurationException::class, "Unknown rule 'test/none'. (in preset test/broken)");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleA::class, ['x' => 1])), ConfigurationException::class, 'Rule test/a has no options.');
	// the message names the layer that set the options, because that is where the reader has to go
	Assert::exception(fn() => resolve(Config::create()->enable(RuleC::class, ['max' => 'no'])), ConfigurationException::class, "Invalid options of rule test/c set by the configuration: The item 'max' expects to be int, 'no' given.");
	Assert::exception(fn() => resolve(Config::create()->preset(BasePreset::class)->enable(RuleC::class, ['maxx' => 1])), ConfigurationException::class, "Invalid options of rule test/c set by test/base and the configuration: Unexpected item 'maxx', did you mean 'max'?");
	Assert::exception(fn() => resolve(Config::create()->enable(RuleD::class, fn() => new RuleA)), ConfigurationException::class, 'The factory of rule test/d returned RuleA instead of RuleD.');
});
