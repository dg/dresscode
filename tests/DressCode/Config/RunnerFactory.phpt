<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException, NodeRule, Override, Plugin, PluginManifest, Profile, RuleContext, RuleInfo, Stage};
use DressCode\Config\{PhpVersionSource, RunnerFactory};
use DressCode\Reporters\NullReporter;
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
