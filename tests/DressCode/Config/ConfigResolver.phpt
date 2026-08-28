<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException, Decision, Domain, NodeRule, Override, Plugin, PluginManifest, Profile, Rule, RuleInfo, Stage};
use DressCode\Config\{ConfigResolver, PluginRegistry, ResolvedRule, RuleBuilder};
use DressCode\Domains\Count;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


abstract class ResolvedTestRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}


	/** The decision of a rule of the project named after it, with no other value than whether it holds. */
	protected static function decide(string $key, string $description): Decision
	{
		return new Decision("project.$key", Domain::state('forbidden'), $description);
	}
}


#[RuleInfo(Stage::Formatting)]
final class RuleA extends ResolvedTestRule
{
	public static function getDecisions(): array
	{
		return [self::decide('a', 'A')];
	}
}


#[RuleInfo(Stage::Formatting)]
final class RuleB extends ResolvedTestRule
{
	public static function getDecisions(): array
	{
		return [self::decide('b', 'B')];
	}
}


#[RuleInfo(Stage::Formatting)]
final class RuleC extends ResolvedTestRule
{
	public int $max = 0;


	public static function getDecisions(): array
	{
		return [self::decide('c', 'C'), new Decision('project.cMax', new Count, 'The most of C', parameter: true, default: 3)];
	}


	public function configure(DressCode\Values $values): void
	{
		$this->max = $values->get('project.cMax')->getCount()[0];
	}
}


#[RuleInfo(Stage::Formatting)]
final class RuleD extends ResolvedTestRule
{
	public function __construct(
		public readonly string $dependency,
	) {
	}


	public static function getDecisions(): array
	{
		return [self::decide('d', 'D')];
	}
}


#[RuleInfo(Stage::Formatting, requires: ['php' => '>=8.4'])]
final class RuleFuture extends ResolvedTestRule
{
	public static function getDecisions(): array
	{
		return [self::decide('future', 'Of a newer PHP')];
	}
}


const ResolvedTestRules = [
	RuleA::class, RuleB::class, RuleC::class, RuleD::class, RuleFuture::class,
];


/**
 * @param  list<string>  $keys
 * @return array<string, array<string, string>>
 */
function projectDecisions(array $keys, string $value = 'forbidden'): array
{
	return ['project' => array_fill_keys($keys, $value)];
}


/** The presets of the tests, files of the shape of a configuration, by their names. */
const ResolvedTestPresets = [
	'test/base' => "project:\n\ta: forbidden\n\tc: forbidden\n\tcMax: 5\n\tb: forbidden\n",
	'test/child' => "use: test/base\n\nproject:\n\tb: keep\n",
	'test/future-preset' => "project:\n\ta: forbidden\n\tfuture: forbidden\n",
	'test/styled' => "use: test/base\n\nindentation:\n\tunit: 2 spaces\nfile:\n\tlineEnding: LF\n",
	'test/broken' => "project:\n\tnone: forbidden\n",
	'test/deciding' => "fixRisky: [RuleA]\n",
	'test/targeting' => "targets: {php: '8.2'}\n",
	'test/plugin-preset' => "use: [ProjectPlugin]\n",
];


final class ProjectPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest;
	}
}


/** The registry knowing the rules of the project the tests name. */
function createRegistry(): PluginRegistry
{
	static $dir;
	$dir ??= createTempDir('config-resolver-presets');
	$registry = new PluginRegistry;
	foreach (ResolvedTestRules as $class) {
		$registry->registerRule($class);
	}

	foreach (ResolvedTestPresets as $name => $content) {
		$file = "$dir/" . basename($name) . '.neon';
		file_put_contents($file, $content);
		$registry->registerPreset($name, $file);
	}

	return $registry;
}


function createResolver(): ConfigResolver
{
	return new ConfigResolver(createRegistry());
}


/** @return list<Rule> */
function resolve(Config $config, string $php = '8.3', ?Profile $commandLine = null): array
{
	return RuleBuilder::buildRules(createResolver()->resolve($config, $php, commandLine: $commandLine));
}


/** The name a rule of the test goes by in the assertions: `RuleFuture` is `test/future`. */
function testName(string $class): string
{
	return str_starts_with($class, 'Rule') ? 'test/' . lcfirst(substr($class, 4)) : $class;
}


/**
 * The rules of the project among them, by the names of the tests.
 * @param  list<Rule>  $rules
 * @return list<string>
 */
function names(array $rules): array
{
	return array_values(array_filter(
		array_map(fn(Rule $rule) => testName($rule::class), $rules),
		fn(string $name) => str_starts_with($name, 'test/'),
	));
}


test('parents first, the child overrides the keys it names, the rules in the order of their registration', function () {
	$rules = resolve(new Config(use: ['test/child']));
	Assert::same(['test/a', 'test/c'], names($rules));
	$c = array_values(array_filter($rules, fn(Rule $rule) => $rule instanceof RuleC))[0];
	Assert::same(5, $c->max);
});


test('the configuration overrides the presets, and a rule of the project is built by its factory', function () {
	$config = new Config(
		use: ['test/child'],
		rules: [RuleD::class => fn() => new RuleD('dep')],
		decisions: ['project' => ['b' => 'forbidden', 'a' => 'keep', 'd' => 'forbidden']],
	);
	$rules = resolve($config);
	Assert::same(['test/b', 'test/c', 'test/d'], names($rules));
	$d = array_values(array_filter($rules, fn(Rule $rule) => $rule instanceof RuleD))[0];
	Assert::same('dep', $d->dependency);
	Assert::exception(
		fn() => resolve(new Config(rules: [RuleD::class => fn() => new RuleA], decisions: projectDecisions(['d']))),
		ConfigurationException::class,
		'The factory of rule `RuleD` returned `RuleA` instead of `RuleD`.',
	);
});


test('a rule of a construct the target version has not got is left out', function () {
	$resolver = createResolver();
	$resolve = fn(Config $config, string $php) => names(RuleBuilder::buildRules($resolver->resolve($config, $php)));

	Assert::same(['test/a', 'test/future'], $resolve(new Config(use: ['test/future-preset']), '8.4'));
	Assert::same(['test/a'], $resolve(new Config(use: ['test/future-preset']), '8.3'));
	Assert::same([], $resolver->getWarnings()); // coming from a preset it is business as usual

	Assert::same([], $resolve(new Config(decisions: projectDecisions(['future'])), '8.3'));
	Assert::same(['Decision `project.future` needs PHP >=8.4 and the target is 8.3; skipped.'], $resolver->getWarnings());

	// a rule that does not run says why
	$resolved = $resolver->resolve(new Config(use: ['test/future-preset']), '8.3');
	$future = $resolved->findRule(RuleFuture::class);
	Assert::type(ResolvedRule::class, $future);
	Assert::same('it needs PHP >=8.4 and the target is 8.3', $future->inactiveMessage);
	Assert::same(['8.3', "\t", 'majority'], [$resolved->phpVersion, $resolved->indent, $resolved->lineEnding]);

	// a target given as the constraint of composer.json runs the rule where every version it allows has the construct
	Assert::same(['test/a', 'test/future'], $resolve(new Config(use: ['test/future-preset']), '8.4 - 8.6'));
	Assert::same(['test/a'], $resolve(new Config(use: ['test/future-preset']), '^8.3'));
	Assert::same('8.4', $resolver->resolve(new Config, '8.4 - 8.6')->phpVersion);
});


/**
 * Narrows a run to the names and returns the rules of the project that run for a file matching the overrides.
 * @param  list<string>  $only
 * @param  list<int>  $overrides
 * @return list<string>
 */
function narrow(ConfigResolver $resolver, Config $config, array $only, array $overrides = []): array
{
	return array_values(array_filter(
		array_map(fn(ResolvedRule $rule) => testName($rule->class), $resolver->resolve($config, '8.3', $overrides, only: $only)->getActiveRules()),
		fn(string $name) => str_starts_with($name, 'test/'),
	));
}


test('--only keeps what it names of what the configuration comes to, and turns nothing on', function () {
	$resolver = createResolver();

	// a rule by its class, a decision or a section
	Assert::same(['test/c'], narrow($resolver, new Config(use: ['test/child']), [RuleC::class]));
	Assert::same(['test/c'], narrow($resolver, new Config(use: ['test/child']), ['project.c']));
	Assert::same(['test/a', 'test/c'], narrow($resolver, new Config(use: ['test/child']), ['project']));
	$narrowed = $resolver->resolve(new Config(use: ['test/child']), '8.3', only: [RuleC::class]);
	Assert::same('the run is narrowed to other decisions', $narrowed->findRule(RuleA::class)?->inactiveMessage);

	// a decision of the core runs its rule alone
	Assert::same([DressCode\Rules\ControlFlow\FallThroughCommentRule::class], array_map(
		fn(ResolvedRule $rule) => $rule->class,
		$resolver->resolve(new Config(use: ['nette']), '8.3', only: ['controlFlow.switchFallThrough'])->getActiveRules(),
	));

	// a preset stands for every rule owning a decision it and its parents make, and not for what the configuration
	// added; one the configuration turned off stays off, and the run says so
	$resolver = createResolver();
	$config = new Config(use: ['test/child'], decisions: projectDecisions(['d']));
	Assert::same(['test/a', 'test/c'], narrow($resolver, $config, ['test/child']));
	Assert::same(['test/a', 'test/c'], narrow($resolver, $config, ['test/base']));
	Assert::same(['test/a', 'test/c', 'test/d'], narrow($resolver, $config, ['test/base', RuleD::class]));
	Assert::same([], $resolver->getWarnings());
	$resolver = createResolver();
	Assert::same(['test/a', 'test/c'], narrow($resolver, new Config(use: ['test/base'], decisions: projectDecisions(['b'], 'keep')), ['test/base']));
	Assert::same(
		['Option `--only` keeps 2 of the 3 rules of preset `test/base` that may run here, without `project.b`, which the configuration turns off.'],
		$resolver->getWarnings(),
	);

	// a preset stands for the rules owning its decisions
	Assert::contains(DressCode\Rules\ControlFlow\UselessReturnRule::class, array_map(
		fn(ResolvedRule $rule) => $rule->class,
		createResolver()->resolve(new Config(use: ['nette']), '8.3', only: ['nette'])->getActiveRules(),
	));

	// a section named like a preset is written with `.*`, the bare name says both and is refused
	Assert::exception(
		fn() => createResolver()->resolve(new Config(use: ['nette']), '8.3', only: ['correctness']),
		ConfigurationException::class,
		'Name `correctness` is both a section of decisions and the preset `dresscode/correctness`; write `correctness.*` for the section or `dresscode/correctness` for the preset.',
	);
	Assert::exception(
		fn() => createResolver()->resolve(new Config(use: ['nette'], fixRisky: ['types']), '8.3'),
		ConfigurationException::class,
		'Name `types` is both a section %a%',
	);
	$section = array_map(fn(ResolvedRule $rule) => $rule->class, createResolver()->resolve(new Config(use: ['nette']), '8.3', only: ['correctness.*'])->getActiveRules());
	$preset = array_map(fn(ResolvedRule $rule) => $rule->class, createResolver()->resolve(new Config(use: ['nette']), '8.3', only: ['dresscode/correctness'])->getActiveRules());
	Assert::true($section !== [] && $section !== $preset);
	Assert::exception(
		fn() => createResolver()->resolve(new Config(use: ['nette']), '8.3', only: ['nothing.*']),
		ConfigurationException::class,
		'Unknown section `nothing`.',
	);

	// a rule only an override turns on runs where the override applies
	$resolver = createResolver();
	$overridden = new Config(use: ['test/child'], overrides: [new Override(['tests'], new Profile(decisions: projectDecisions(['d'])))]);
	Assert::same([], narrow($resolver, $overridden, [RuleD::class]));
	Assert::same(['test/d'], narrow($resolver, $overridden, [RuleD::class], [0]));
});


test('a name of --only that lets in nothing that runs is an error, not an empty run', function () {
	$resolver = createResolver();
	Assert::exception(
		fn() => narrow($resolver, new Config(decisions: projectDecisions(['future'])), [RuleFuture::class]),
		ConfigurationException::class,
		'Option `--only` names rule `RuleFuture`, which cannot run here: it needs PHP >=8.4 and the target is 8.3.',
	);
	Assert::exception(
		fn() => narrow($resolver, new Config(use: ['test/child']), [RuleB::class]),
		ConfigurationException::class,
		'Option `--only` names rule `RuleB`, which cannot run here: its decisions are `keep`.',
	);
	Assert::exception(
		fn() => narrow($resolver, new Config(use: ['test/child']), ['project.d']),
		ConfigurationException::class,
		'Option `--only` names decision `project.d`, which has no rule that runs here; `--set project.d=<value>` decides it for the run.',
	);
	Assert::exception(
		fn() => narrow($resolver, new Config(use: ['test/child']), ['test/basee']),
		ConfigurationException::class,
		'Unknown decision, preset or rule `test/basee`. Did you mean `test/base`?',
	);
});


test('a configuration without a preset', function () {
	Assert::same(['test/a', 'test/c'], names(resolve(new Config(decisions: projectDecisions(['c', 'a'])))));
	Assert::same([], names(resolve(new Config)));
});


test('fixRisky names a preset for every rule it mentions, as only does, and the command line adds to it', function () {
	$resolver = createResolver();
	$accepted = fn(Config $config, ?Profile $commandLine = null) => array_map(
		fn(string $class) => $resolver->resolve($config, '8.3', commandLine: $commandLine)->findRule($class)?->fixRisky,
		[RuleA::class, RuleB::class, RuleD::class],
	);
	Assert::same([true, true, false], $accepted(new Config(use: ['test/base'], fixRisky: ['test/base'], decisions: projectDecisions(['d']))));
	Assert::same([false, false, true], $accepted(new Config(use: ['test/base'], decisions: projectDecisions(['d'])), new Profile(fixRisky: [RuleD::class])));
	Assert::same([false, false, true], $accepted(new Config(use: ['test/base'], fixRisky: ['project.d'], decisions: projectDecisions(['d']))));
	// the fixes are accepted by the decision, a rule standing for every one of its requirements and a section for those under it
	Assert::same(['project.c' => true], $resolver->resolve(new Config(fixRisky: [RuleC::class]), '8.3')->fixRisky);
	Assert::same(['project.a', 'project.b'], array_slice(array_keys($resolver->resolve(new Config(warnOnly: ['project']), '8.3')->warnOnly), 0, 2));
	Assert::exception(fn() => $accepted(new Config(fixRisky: ['test/nope'])), ConfigurationException::class, 'Unknown decision, preset or rule `test/nope`.%a?%');
});


test('a rule an override turns on runs somewhere, however the override decides it', function () {
	// a word on a structure decides every decision under it
	$config = new Config(fixRisky: ['multiline.operatorPosition'], overrides: [new Override(['tests'], new Profile(decisions: ['multiline' => ['operatorPosition' => 'lineStart']]))]);
	$resolver = createResolver();
	$resolver->resolve($config, '8.3');
	Assert::same([], $resolver->getWarnings());
	Assert::noError(fn() => createResolver()->resolve($config, '8.3', only: ['multiline.operatorPosition']));
});


test('a comment the configuration names silences rules, and an override says its own', function () {
	$resolver = createResolver();
	$config = new Config(
		suppressionComments: ['~ok~' => RuleA::class],
		overrides: [new Override(['tests'], new Profile(suppressionComments: ['~ok~' => [RuleB::class, RuleC::class], '~other~' => RuleA::class]))],
	);

	Assert::same(['~ok~' => ['project.a']], $resolver->resolve($config, '8.3')->suppressionComments);
	Assert::same(['~ok~' => ['project.b', 'project.c'], '~other~' => ['project.a']], $resolver->resolve($config, '8.3', [0])->suppressionComments);
	Assert::same(['~ok~' => ['project']], $resolver->resolve(new Config(suppressionComments: ['~ok~' => 'project']), '8.3')->suppressionComments);
	Assert::exception(fn() => $resolver->resolve(new Config(suppressionComments: ['~ok~' => 'test/nope']), '8.3'), ConfigurationException::class, '`suppressionComments` names `test/nope`, which is no decision, section or rule.');
	Assert::exception(fn() => new Config(suppressionComments: ['ok' => 'test/a']), InvalidArgumentException::class, '`ok` in `suppressionComments` is not a regular expression%a%');
});


test('an override lays a profile of its own over the configuration, its presets included', function () {
	$resolver = createResolver();
	$config = new Config(
		use: ['test/base'],
		rules: [RuleD::class => fn() => new RuleD('dep')],
		nameResolution: 'certain',
		fixRisky: [RuleA::class],
		overrides: [
			new Override(['tests'], new Profile(use: ['test/child', 'test/styled'], nameResolution: 'uncertain', warnOnly: [RuleA::class], decisions: projectDecisions(['d']))),
		],
		decisions: ['project' => ['cMax' => 7]],
	);
	$base = $resolver->resolve($config, '8.3');
	$tests = $resolver->resolve($config, '8.3', [0]);

	// a preset of the override lies above the configuration, and one the configuration already has is not laid again
	Assert::same(['test/base'], $base->use);
	Assert::same(['test/base', 'test/child', 'test/styled'], $tests->use);
	Assert::same(['test/a', 'test/b', 'test/c'], names(RuleBuilder::buildRules($base)));
	Assert::same(['test/a', 'test/c', 'test/d'], names(RuleBuilder::buildRules($tests)));
	Assert::same(7, $tests->values->get('project.cMax')->getCount()[0]);
	Assert::same('its decisions are `keep`', $tests->findRule(RuleB::class)?->inactiveMessage);
	Assert::same([["\t", 'majority'], ['  ', "\n"]], [[$base->indent, $base->lineEnding], [$tests->indent, $tests->lineEnding]]);

	// a certain resolution turns on the guard of its lists, and a file that ends up uncertain has none to guard
	Assert::same(['certain', 'uncertain'], [$base->nameResolution, $tests->nameResolution]);
	Assert::true($base->findRule(DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class)?->isActive());
	Assert::false($tests->findRule(DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class)?->isActive());

	// a list adds up: what the configuration accepts holds under the override, which adds what it says
	Assert::same([true, false], [$base->findRule(RuleA::class)?->fixRisky, $base->findRule(RuleA::class)?->warnOnly]);
	Assert::same([true, true], [$tests->findRule(RuleA::class)?->fixRisky, $tests->findRule(RuleA::class)?->warnOnly]);
});


test('the command line lies over the overrides', function () {
	$resolver = createResolver();
	$config = new Config(decisions: projectDecisions(['a']), overrides: [new Override(['tests'], new Profile(decisions: projectDecisions(['a'], 'keep')))]);
	Assert::same('its decisions are `keep`', $resolver->resolve($config, '8.3', [0])->findRule(RuleA::class)?->inactiveMessage);
	$rule = $resolver->resolve($config, '8.3', [0], new Profile(decisions: projectDecisions(['a'])))->findRule(RuleA::class);
	Assert::true($rule?->isActive());
	Assert::same('the command line', $rule->source?->describe());
});


test('the version of PHP a profile says is the target of its files, raised to the oldest one DressCode fixes code for', function () {
	$resolver = createResolver();
	$config = new Config(decisions: projectDecisions(['future']), overrides: [
		new Override(['legacy'], new Profile(targets: ['php' => '8.3'])),
		new Override(['ancient'], new Profile(targets: ['php' => '7.4'])),
	]);
	Assert::true($resolver->resolve($config, '8.4')->findRule(RuleFuture::class)?->isActive());
	// every override is resolved with the configuration, so what it gets wrong is said before any of its files
	Assert::contains('The target PHP 7.4 is older than PHP 8.0, the oldest DressCode fixes code for;', implode("\n", $resolver->getWarnings()));

	$legacy = $resolver->resolve($config, '8.4', [0]);
	Assert::same('8.3', $legacy->phpVersion);
	Assert::same('it needs PHP >=8.4 and the target is 8.3', $legacy->findRule(RuleFuture::class)?->inactiveMessage);

	Assert::same('8.0', $resolver->resolve($config, '8.4', [1])->phpVersion);
});


test('what the namespaces declare adds up over the layers, and only the configuration makes it certain', function () {
	$resolver = createResolver();
	$uncertain = $resolver->resolve(
		new Config(namespaces: ['functions' => ['App\helper', 'fw\config\SERVICE'], 'constants' => ['fw\VERSION', 'Fw\version']]),
		Config::DefaultPhpVersion,
	);
	Assert::same(['App\helper' => 'the configuration', 'fw\config\SERVICE' => 'the configuration'], $uncertain->namespacedFunctions);
	Assert::same(['fw\VERSION' => 'the configuration', 'Fw\version' => 'the configuration'], $uncertain->namespacedConstants);
	Assert::same('uncertain', $uncertain->nameResolution);
	Assert::false($uncertain->toNamespacedSymbols()->complete);
	Assert::true($uncertain->toNamespacedSymbols()->hasFunction('App\helper'));

	$certain = $resolver->resolve(new Config(nameResolution: 'certain'), Config::DefaultPhpVersion);
	Assert::true($certain->toNamespacedSymbols()->complete);

	// a certain resolution turns on the guard of its lists, which nothing turns off, the fixes resting on the lists
	$guard = DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class;
	Assert::true($certain->findRule($guard)?->isActive());
	Assert::false($uncertain->findRule($guard)?->isActive());

	// an override adds to the lists for its files
	$overridden = new Config(namespaces: ['functions' => ['App\helper']], overrides: [new Override(['tests'], new Profile(namespaces: ['functions' => ['App\Tests\fixture']]))]);
	Assert::same(
		['App\helper' => 'the configuration', 'App\Tests\fixture' => 'the override for tests'],
		createResolver()->resolve($overridden, Config::DefaultPhpVersion, [0])->namespacedFunctions,
	);

	Assert::exception(
		fn() => new Config(namespaces: ['functions' => ['strlen']]),
		InvalidArgumentException::class,
		'`strlen` is in no namespace, and a global function needs no listing.',
	);
});


test('the style is what the decisions of the last layer say, else a tab and the line ending each file mostly has', function () {
	$resolver = createResolver();
	$style = function (Config $config) use ($resolver): array {
		$resolved = $resolver->resolve($config, '8.3');
		return [$resolved->indent, $resolved->lineEnding];
	};
	Assert::same(["\t", 'majority'], $style(new Config));
	Assert::same(["\t", 'majority'], $style(new Config(use: ['test/child'])));
	Assert::same(['  ', "\n"], $style(new Config(use: ['test/styled'])));
	Assert::same(['  ', "\n"], $style(new Config(use: ['test/styled', 'test/child'])));
	Assert::same(['    ', "\n"], $style(new Config(use: ['test/styled'], decisions: ['indentation' => ['unit' => '4 spaces']])));
	Assert::same(['  ', "\r\n"], $style(new Config(use: ['test/styled'], decisions: ['file' => ['lineEnding' => 'CRLF']])));
	Assert::same(['  ', 'majority'], $style(new Config(use: ['test/styled'], decisions: ['file' => ['lineEnding' => 'majority']])));
	Assert::same(["\t", 'majority'], $style(new Config(use: ['test/styled'], decisions: ['indentation' => ['unit' => 'keep'], 'file' => ['lineEnding' => 'keep']])));
});


test('use lays its presets in the order written, each where it is named first, and says what a preset named again does not do', function () {
	// a preset its child brings along is not named twice by the project, and the command line names what it wants
	$resolver = createResolver();
	$resolver->resolve(new Config(use: ['nette']), '8.3', commandLine: new Profile(use: ['perCs']));
	Assert::same(['The command line uses preset `dresscode/perCs`, which preset `dresscode/nette` already brings; the entry does nothing.'], $resolver->getWarnings());

	Assert::exception(
		fn() => createResolver()->resolve(new Config(use: ['test/plugin-preset']), '8.3'),
		ConfigurationException::class,
		'Preset `test/plugin-preset`: Plugin `ProjectPlugin` is used by the configuration of the project, never by a preset or an override.',
	);
	Assert::exception(
		fn() => createResolver()->resolve(new Config(use: [RuleC::class]), '8.3'),
		ConfigurationException::class,
		'Unknown preset `RuleC`.%a?%',
	);

	// a plugin is no layer, so it is listed apart, and the command line may name one too
	$resolved = createResolver()->resolve(new Config(use: ['test/base', ProjectPlugin::class]), '8.3', commandLine: new Config(use: [new ProjectPlugin]));
	Assert::same(['test/base'], $resolved->use);
	Assert::same([ProjectPlugin::class, ProjectPlugin::class], $resolved->plugins);
});


test('errors', function () {
	Assert::exception(fn() => resolve(new Config(decisions: projectDecisions(['none']))), ConfigurationException::class, '%a%`project.none`%a%');
	Assert::exception(fn() => resolve(new Config(use: ['test/broken'])), ConfigurationException::class, 'Preset `test/broken`: %a%`project.none`%a%');
	Assert::exception(fn() => resolve(new Config(use: ['test/deciding'])), ConfigurationException::class, 'Preset `test/deciding` sets `fixRisky`, which the project decides, not a preset.');
	Assert::exception(fn() => resolve(new Config(use: ['test/targeting'])), ConfigurationException::class, 'Preset `test/targeting` sets `targets`, which the project decides, not a preset.');
	Assert::exception(fn() => resolve(new Config(overrides: [new Override(['tests'], new Profile(use: ['test/nope']))])), ConfigurationException::class, 'The override for `tests`: Unknown preset `test/nope`.');
	Assert::exception(fn() => resolve(new Config(overrides: [new Override(['tests'], new Profile(fixRisky: ['test/nope']))])), ConfigurationException::class, 'The override for `tests`: Unknown decision, preset or rule `test/nope`.%a?%');
	Assert::exception(
		fn() => createResolver()->resolve(new Config(overrides: [new Override(['tests'], new Profile(warnOnly: ['test/nope']))]), '8.3', [0]),
		ConfigurationException::class,
		'The override for `tests`: Unknown decision, preset or rule `test/nope`.%a?%',
	);
});
