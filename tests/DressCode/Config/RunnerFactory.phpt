<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException, NodeRule, Override, Plugin, PluginManifest, Preset, PresetInfo, Profile, RuleContext, RuleInfo, Stage};
use DressCode\Config\{PhpVersionSource, RunnerFactory};
use DressCode\Reporters\NullReporter;
use DressCode\Rules\Literals\StringQuotesRule;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\{Assert, FileMock};

require __DIR__ . '/../../bootstrap.php';

$fixtures = str_replace('\\', '/', __DIR__) . '/fixtures';


#[RuleInfo('test/a', Stage::Structure)]
final class ReportContext extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, $context->phpVersion . ' ' . json_encode($context->style->indent) . json_encode($context->style->lineEnding));
	}
}


#[RuleInfo('test/b', Stage::Structure)]
final class ReportVariable extends NodeRule
{
	public function __construct(
		private readonly bool $report = true,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($this->report) {
			$context->report($node, 'A variable');
		}
	}
}


#[PresetInfo('test/double')]
final class DoubleQuotesPreset implements Preset
{
	public function getProfile(): Profile
	{
		return new Profile(rules: [StringQuotesRule::class => 'double']);
	}
}


final class ProjectPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			rules: [ReportContext::class],
			plugins: [new NestedPlugin],
			skipWhen: fn(string $content) => str_contains($content, '@generated'),
		);
	}
}


final class DoubleQuotesPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(presets: [DoubleQuotesPreset::class]);
	}
}


final class NestedPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(excludePaths: ['sub']);
	}
}


test('the PHP target comes from the configuration, composer.json or the default', function () use ($fixtures) {
	$factory = new RunnerFactory;
	Assert::same(['>=8.1 <8.6', PhpVersionSource::Composer], $factory->resolvePhpTarget(new Config, "$fixtures/project"));
	Assert::same(['8.4', PhpVersionSource::Configuration], $factory->resolvePhpTarget(new Config(targets: ['php' => '8.4']), "$fixtures/project"));
	// a directory without a composer.json of its own is answered by the nearest one above it
	Assert::same(['>=8.1 <8.6', PhpVersionSource::Composer], $factory->resolvePhpTarget(new Config, "$fixtures/project/src"));
	// above the fixtures there is the composer.json of DressCode itself
	Assert::same(PhpVersionSource::Composer, $factory->resolvePhpTarget(new Config, $fixtures)[1]);
	Assert::same([Config::DefaultPhpVersion, PhpVersionSource::Default], $factory->resolvePhpTarget(new Config, sys_get_temp_dir()));

	// the version the rules ask about is the lowest of the target
	$factory->createRunner(new Config, "$fixtures/project");
	Assert::same(['8.1', PhpVersionSource::Composer], $factory->getPhpVersion());
});


test('a target older than the oldest PHP DressCode fixes code for is raised to it with a warning', function () {
	$root = createTempDir('php-floor');
	file_put_contents("$root/composer.json", '{"require": {"php": "^7.4 || ^8.0"}}');
	$warning = 'The target PHP 7.4 is older than PHP 8.0, the oldest DressCode fixes code for; the code is checked as PHP 8.0, so a fix may write syntax the target does not have.';

	$factory = new RunnerFactory;
	$runner = $factory->createRunner(new Config(rules: [ReportContext::class => true]), $root);
	Assert::same(['8.0', PhpVersionSource::Composer], $factory->getPhpVersion());
	Assert::same([$warning], $factory->getWarnings());
	Assert::match('8.0 %a%', $runner->processFile('x.php', "<?php\n\$a;\n")->violations[0]->message);

	// every engine the factory builds starts with warnings of its own
	$factory->createRunner(new Config(targets: ['php' => '7.4']), $root);
	Assert::same(['8.0', PhpVersionSource::Configuration], $factory->getPhpVersion());
	Assert::same([$warning], $factory->getWarnings());
});


test('the constraint of require.php', function () use ($fixtures) {
	$detect = fn(string $json) => RunnerFactory::detectPhpTarget(FileMock::create($json, 'json'));
	Assert::same('8.2 - 8.5', $detect('{"require": {"php": "8.2 - 8.5"}}'));
	Assert::same('^7.4 || ^8.0', $detect('{"require": {"php": "^7.4 || ^8.0"}}'));
	Assert::same('>8.0', $detect('{"require": {"php": ">8.0"}}'));
	// a constraint without a lower bound says nothing, not the first number it names
	Assert::null($detect('{"require": {"php": "<8.4"}}'));
	Assert::null($detect('{"require": {"php": "not a constraint"}}'));
	Assert::null($detect('{"require": {"php": ""}}'));
	Assert::null($detect('{"require": {"php": "*"}}'));
	Assert::null($detect('{"require": {}}'));
	Assert::null($detect('not json'));
	Assert::null(RunnerFactory::detectPhpTarget("$fixtures/none.json"));
	Assert::null(RunnerFactory::detectPhpTarget(null));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$runner = (new RunnerFactory)->createRunner(new Config(rules: [ReportContext::class => true], indent: 2, excludePaths: ['sub']), "$fixtures/project");
	Assert::same([], $runner->findFiles(['src']));
	$result = $runner->processFile('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$runner = (new RunnerFactory)->createRunner(new Config(rules: [ReportContext::class => true], indent: 2, lineEnding: 'LF'), "$fixtures/project");
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $runner->processFile('x.php', "<?php\r\n\$a;\r\n")->violations));
});


test('the command line lies over the configuration', function () use ($fixtures) {
	$runner = (new RunnerFactory)->createRunner(
		new Config(rules: [ReportContext::class => true]),
		"$fixtures/project",
		commandLine: new Profile(rules: [ReportContext::class => false, ReportVariable::class => true]),
	);
	Assert::same(['test/b'], array_map(fn($v) => $v->ruleName, $runner->processFile('x.php', "<?php\n\$a;\n")->violations));
});


test('a plugin makes its rules known by name, and brings the paths it leaves out and the files it skips', function () use ($fixtures) {
	Assert::exception(
		fn() => (new RunnerFactory)->createRunner(new Config(rules: ['test/a' => true]), "$fixtures/project"),
		ConfigurationException::class,
		'Unknown rule `test/a`.',
	);

	$root = createTempDir('runner-factory-plugins');
	mkdir("$root/sub");
	file_put_contents("$root/checked.php", "<?php\n\$a;\n");
	file_put_contents("$root/generated.php", "<?php // @generated\n\$a;\n");
	file_put_contents("$root/skipped.php", "<?php // @skip\n\$a;\n");
	file_put_contents("$root/sub/excluded.php", "<?php\n\$a;\n");
	$runner = (new RunnerFactory)->createRunner(
		new Config(
			plugins: [ProjectPlugin::class],
			rules: ['test/a' => true],
			skipWhen: fn(string $content) => str_contains($content, '@skip'),
		),
		$root,
	);
	Assert::same(['test/a'], array_map(fn($rule) => RuleInfo::of($rule)->name, $runner->getProcessor()->rules));

	// the paths of every layer add up, and a file any layer skips is skipped
	$files = $runner->findFiles(['.']);
	Assert::same(['checked.php', 'generated.php', 'skipped.php'], $files);
	Assert::same(['checked.php'], array_map(fn($result) => $result->path, $runner->run($files, false, new NullReporter)->files));
});


test('the page of a rule is where the configuration that names it by class says, or the plugin that brings it', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$factory->createRunner(new Config(rules: [ReportContext::class => true], ruleUrl: 'https://wiki.acme.dev/{slug}'), "$fixtures/project");
	Assert::same('https://wiki.acme.dev/a', $factory->registry->getRuleUrl('test/a'));
	Assert::same('https://dresscode.run/rules/uselessReturn', $factory->registry->getRuleUrl('dresscode/uselessReturn'));

	$factory = new RunnerFactory;
	$factory->createRunner(new Config(plugins: [ProjectPlugin::class], ruleUrl: 'https://wiki.acme.dev/{slug}'), "$fixtures/project");
	Assert::null($factory->registry->getRuleUrl('test/a'));
});


test('plugins takes plugins alone, a rule and a preset are named in their own keys', function () {
	$create = fn(mixed ...$plugins) => new Config(plugins: array_values($plugins));
	Assert::exception(
		fn() => $create(ReportContext::class),
		InvalidArgumentException::class,
		'Plugin `ReportContext` is not a plugin; a rule is named in `rules` and a preset in `presets`.',
	);
	Assert::exception(
		fn() => $create('DressCode\Missing'),
		InvalidArgumentException::class,
		'Plugin `DressCode\Missing` is not a plugin; a rule is named in `rules` and a preset in `presets`.',
	);
	Assert::exception(
		fn() => $create(new stdClass),
		InvalidArgumentException::class,
		'Plugin `stdClass` is not a plugin; a rule is named in `rules` and a preset in `presets`.',
	);
	Assert::exception(
		fn() => new ReflectionClass(PluginManifest::class)->newInstanceArgs(['plugins' => [ReportContext::class]]),
		InvalidArgumentException::class,
		'Plugin `ReportContext` is not a plugin; a rule is named in `rules` and a preset in `presets`.',
	);
});


test('an override turns a rule off under its class as under its name, an unknown one is an error before any file', function () use ($fixtures) {
	$byClass = new Config(rules: [ReportContext::class => true], overrides: [new Override(['sub'], rules: [ReportContext::class => false])]);
	$runner = (new RunnerFactory)->createRunner($byClass, "$fixtures/project");
	Assert::same([], $runner->processFile('src/sub/x.php', "<?php\n\$a;\n")->violations);
	Assert::count(1, $runner->processFile('src/x.php', "<?php\n\$a;\n")->violations);

	$unknown = new Config(rules: [ReportContext::class => true], overrides: [new Override(['sub'], rules: ['test/nope' => false])]);
	Assert::exception(
		fn() => (new RunnerFactory)->createRunner($unknown, "$fixtures/project"),
		ConfigurationException::class,
		'The override for `sub`: Unknown rule `test/nope`.',
	);

	// so is an option no rule takes, which would otherwise wait for a file of the override
	$invalid = new Config(overrides: [new Override(['sub'], rules: ['stringQuotes' => ['quote' => 'single']])]);
	Assert::exception(
		fn() => (new RunnerFactory)->createRunner($invalid, "$fixtures/project"),
		ConfigurationException::class,
		"Invalid options of rule `dresscode/stringQuotes` set by the override for `sub`: Unexpected item 'quote', did you mean 'quotes'?",
	);
});


test('an override brings its presets, its style, its name resolution and its warnings to its files', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$runner = $factory->createRunner(
		new Config(
			plugins: [DoubleQuotesPlugin::class],
			rules: [ReportContext::class => true],
			nameResolution: 'certain',
			overrides: [new Override(['sub'], presets: ['test/double'], indent: 2, nameResolution: 'uncertain', warnOnly: [ReportContext::class])],
		),
		"$fixtures/project",
	);
	$describe = fn(string $path) => array_map(
		fn($violation) => "$violation->ruleName {$violation->severity->name} $violation->message",
		$runner->processFile($path, "<?php\n\$a = 'text';\n")->violations,
	);
	Assert::same(['test/a Error 8.1 "\t""\n"'], $describe('src/x.php'));
	$sub = $describe('src/sub/x.php');
	Assert::count(2, $sub);
	Assert::same('test/a Warning 8.1 "  ""\n"', $sub[0]);
	Assert::match('dresscode/stringQuotes Error %a%', $sub[1]);

	$guard = 'dresscode/noUnlistedNamespacedDeclarations';
	$sub = $factory->resolveConfigFor($runner->findOverridesFor('src/sub/x.php'));
	Assert::same(['certain', true], [$factory->getResolvedConfig()->nameResolution, $factory->getResolvedConfig()->getRule($guard)?->isActive()]);
	Assert::same(['uncertain', false], [$sub->nameResolution, $sub->getRule($guard)?->isActive()]);
});


test('a runner keeps the configuration it was built from, whatever the factory builds after it', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$runner = $factory->createRunner(
		new Config(rules: [ReportContext::class => true], overrides: [new Override(['sub'], rules: [ReportContext::class => false])]),
		"$fixtures/project",
	);
	$factory->createRunner(new Config(rules: [ReportVariable::class => true]), "$fixtures/project");

	// both processors are built lazily, the base one and the one of the override, and both after the second runner
	Assert::same(['test/a'], array_map(fn($v) => $v->ruleName, $runner->processFile('src/x.php', "<?php\n\$a;\n")->violations));
	Assert::same([], $runner->processFile('src/sub/x.php', "<?php\n\$a;\n")->violations);
});


test('the name of the baseline is judged even before the file exists', function () use ($fixtures) {
	Assert::null(RunnerFactory::loadBaseline(new Config, $fixtures));
	Assert::null(RunnerFactory::loadBaseline(new Config(baseline: 'baseline.neon'), $fixtures)); // no file yet
	Assert::exception(
		fn() => RunnerFactory::loadBaseline(new Config(baseline: 'baseline.txt'), $fixtures),
		ConfigurationException::class,
		'Baseline file `%a%baseline.txt` must be a `.neon` or a `.php` file.',
	);
});
