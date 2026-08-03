<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException, Decision, Domain, NodeRule, Override, Plugin, PluginManifest, Profile, RuleContext, RuleInfo, Stage};
use DressCode\Config\{Composer, PhpVersionSource, RunnerFactory};
use DressCode\Engine\Runner;
use DressCode\Reporters\NullReporter;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\{Assert, FileMock};

require __DIR__ . '/../../bootstrap.php';

$fixtures = str_replace('\\', '/', __DIR__) . '/fixtures';


/** A runner built by a factory of its own, as every run of the tool builds one. */
function buildRunner(Config $config, string $root, ?Profile $commandLine = null): Runner
{
	$factory = new RunnerFactory;
	return $factory->createRunner($factory->resolve($config, $root, $commandLine));
}


const ReportsContext = ['project' => ['context' => 'forbidden']];
const ReportsVariables = ['project' => ['variables' => 'forbidden']];


#[RuleInfo(Stage::Structure)]
final class ReportContext extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.context', Domain::state('forbidden'), 'Every variable reports what the run knows')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, $context->phpVersion . ' ' . json_encode($context->style->indent) . json_encode($context->style->lineEnding));
	}
}


#[RuleInfo(Stage::Structure)]
final class ReportVariable extends NodeRule
{
	public function __construct(
		private readonly bool $report = true,
	) {
	}


	public static function getDecisions(): array
	{
		return [new Decision('project.variables', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($this->report) {
			$context->report($node, 'A variable.');
		}
	}
}


#[RuleInfo(Stage::Structure)]
final class PluginRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('plugin.variables', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'A variable.');
	}
}


final class ProjectPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			rules: [PluginRule::class],
			section: 'plugin',
			plugins: [new NestedPlugin],
			skipWhen: fn(string $content) => str_contains($content, '@generated'),
		);
	}
}


final class DoubleQuotesPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(presets: ['test/double' => __DIR__ . '/fixtures/double-quotes.neon']);
	}
}


final class NestedPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(excludePaths: ['sub']);
	}
}


#[RuleInfo(Stage::Structure)]
final class BaseRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('base.variables', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}
}


#[RuleInfo(Stage::Structure)]
final class TopRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('top.variables', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}
}


final class BasePlugin implements Plugin
{
	public static int $manifests = 0;


	public function getManifest(): PluginManifest
	{
		self::$manifests++;
		return new PluginManifest(rules: [BaseRule::class], section: 'base');
	}
}


final class TopPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(rules: [TopRule::class], section: 'top', plugins: [BasePlugin::class]);
	}
}


final class CyclePlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(plugins: [CycleBackPlugin::class]);
	}
}


final class CycleBackPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(plugins: [new CyclePlugin]);
	}
}


test('a plugin is loaded once by its class, given by its name or as an object, the plugins it builds on registered before it', function () use ($fixtures) {
	$factory = new RunnerFactory;
	BasePlugin::$manifests = 0;
	$factory->resolve(new Config(use: [new BasePlugin, TopPlugin::class, new BasePlugin]), "$fixtures/project");
	Assert::same(1, BasePlugin::$manifests);
	Assert::same([BaseRule::class, TopRule::class], array_slice($factory->registry->rules, -2));
});


test('plugins depending on each other in a cycle are an error naming the cycle', function () use ($fixtures) {
	Assert::exception(
		fn() => new RunnerFactory()->resolve(new Config(use: [CyclePlugin::class]), "$fixtures/project"),
		ConfigurationException::class,
		'Plugins depend on each other in a cycle: `CyclePlugin` -> `CycleBackPlugin` -> `CyclePlugin`.',
	);
});


test('the PHP target comes from the configuration, composer.json or the default', function () use ($fixtures) {
	$factory = new RunnerFactory;
	Assert::same(['>=8.1 <8.6', PhpVersionSource::Composer], $factory->getPhpTarget(new Config, "$fixtures/project"));
	Assert::same(['8.4', PhpVersionSource::Configuration], $factory->getPhpTarget(new Config(targets: ['php' => '8.4']), "$fixtures/project"));
	// a directory without a composer.json of its own is answered by the nearest one above it
	Assert::same(['>=8.1 <8.6', PhpVersionSource::Composer], $factory->getPhpTarget(new Config, "$fixtures/project/src"));
	// above the fixtures there is the composer.json of DressCode itself
	Assert::same(PhpVersionSource::Composer, $factory->getPhpTarget(new Config, $fixtures)[1]);
	Assert::same([Config::DefaultPhpVersion, PhpVersionSource::Default], $factory->getPhpTarget(new Config, sys_get_temp_dir()));

	// the version the rules ask about is the lowest of the target
	$resolution = $factory->resolve(new Config, "$fixtures/project");
	Assert::same(['8.1', PhpVersionSource::Composer], [$resolution->phpVersion, $resolution->phpVersionSource]);
});


test('a target older than the oldest PHP DressCode fixes code for is raised to it with a warning', function () {
	$root = createTempDir('php-floor');
	file_put_contents("$root/composer.json", '{"require": {"php": "^7.4 || ^8.0"}}');
	$warning = 'The target PHP 7.4 is older than PHP 8.0, the oldest DressCode fixes code for; the code is checked as PHP 8.0, so a fix may write syntax the target does not have.';

	$factory = new RunnerFactory;
	$resolution = $factory->resolve(new Config(rules: [ReportContext::class], decisions: ReportsContext), $root);
	$runner = $factory->createRunner($resolution);
	Assert::same(['8.0', PhpVersionSource::Composer], [$resolution->phpVersion, $resolution->phpVersionSource]);
	Assert::same([$warning => null], $resolution->warnings);
	Assert::match('8.0 %a%', $runner->processCode('x.php', "<?php\n\$a;\n")->violations[0]->message);

	// every resolution starts with warnings of its own
	$resolution = $factory->resolve(new Config(targets: ['php' => '7.4']), $root);
	Assert::same(['8.0', PhpVersionSource::Configuration], [$resolution->phpVersion, $resolution->phpVersionSource]);
	Assert::same([$warning => null], $resolution->warnings);
});


test('the constraint of require.php', function () use ($fixtures) {
	$detect = fn(string $json) => Composer::detectPhpTarget(FileMock::create($json, 'json'));
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
	Assert::null(Composer::detectPhpTarget("$fixtures/none.json"));
	Assert::null(Composer::detectPhpTarget(null));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$runner = buildRunner(new Config(rules: [ReportContext::class], excludePaths: ['sub'], decisions: ['indentation' => ['unit' => '2 spaces']] + ReportsContext), "$fixtures/project");
	Assert::same([], $runner->findFiles(['src']));
	$result = $runner->processCode('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$runner = buildRunner(new Config(rules: [ReportContext::class], decisions: ['indentation' => ['unit' => '2 spaces'], 'file' => ['lineEnding' => 'LF']] + ReportsContext), "$fixtures/project");
	Assert::same(['Expected LF line endings, CRLF found.', '8.1 "  ""\n"'], array_map(fn($v) => $v->message, $runner->processCode('x.php', "<?php\r\n\$a;\r\n")->violations));
});


test('the command line lies over the configuration', function () use ($fixtures) {
	$runner = buildRunner(
		new Config(rules: [ReportContext::class, ReportVariable::class], decisions: ReportsContext),
		"$fixtures/project",
		commandLine: new Profile(decisions: ['project' => ['context' => 'keep', 'variables' => 'forbidden']]),
	);
	Assert::same(['project.variables'], array_map(fn($v) => $v->decision, $runner->processCode('x.php', "<?php\n\$a;\n")->violations));
});


test('a plugin makes the decisions of its rules known, and brings the paths it leaves out and the files it skips', function () use ($fixtures) {
	Assert::exception(
		fn() => (new RunnerFactory)->resolve(new Config(decisions: ['plugin' => ['variables' => 'forbidden']]), "$fixtures/project"),
		ConfigurationException::class,
		'%a%`plugin%a%',
	);

	$root = createTempDir('runner-factory-plugins');
	mkdir("$root/sub");
	file_put_contents("$root/checked.php", "<?php\n\$a;\n");
	file_put_contents("$root/generated.php", "<?php // @generated\n\$a;\n");
	file_put_contents("$root/skipped.php", "<?php // @skip\n\$a;\n");
	file_put_contents("$root/sub/excluded.php", "<?php\n\$a;\n");
	$factory = new RunnerFactory;
	$resolution = $factory->resolve(
		new Config(
			use: [ProjectPlugin::class],
			skipWhen: fn(string $content) => str_contains($content, '@skip'),
		),
		$root,
		new Profile(decisions: ['plugin' => ['variables' => 'forbidden']]),
	);
	$runner = $factory->createRunner($resolution);
	Assert::same([PluginRule::class], array_map(fn($rule) => $rule->class, $resolution->resolvedConfig->getActiveRules()));

	// the paths of every layer add up, and a file any layer skips is skipped
	$files = $runner->findFiles(['.']);
	Assert::same(['checked.php', 'generated.php', 'skipped.php'], $files);
	Assert::same(['checked.php'], array_map(fn($result) => $result->path, $runner->run($files, false, new NullReporter)->files));
});


test('the page of a rule is where the configuration that names it by class says, or the plugin that brings it', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$factory->resolve(new Config(rules: [ReportContext::class], ruleUrl: 'https://wiki.acme.dev/{slug}'), "$fixtures/project");
	Assert::same('https://wiki.acme.dev/reportContext', $factory->registry->findRuleUrl(ReportContext::class));
	Assert::same('https://dresscode.run/decisions/functions.trailingBareReturn', $factory->registry->findUrl('functions.trailingBareReturn'));

	$factory = new RunnerFactory;
	$factory->resolve(new Config(use: [ProjectPlugin::class], ruleUrl: 'https://wiki.acme.dev/{slug}'), "$fixtures/project");
	Assert::null($factory->registry->findRuleUrl(PluginRule::class));
});


test('use takes presets and plugins, a plugin only in the configuration of the project', function () {
	Assert::same([ProjectPlugin::class], new Config(use: ['perCs', ProjectPlugin::class])->plugins);
	Assert::same(['perCs'], new Config(use: ['perCs', ProjectPlugin::class])->use);
	Assert::exception(
		fn() => new Override(['sub'], new Profile(use: [ProjectPlugin::class])),
		InvalidArgumentException::class,
		'Plugin `ProjectPlugin` is used by the configuration of the project, never by a preset or an override.',
	);
	Assert::exception(
		fn() => new ReflectionClass(PluginManifest::class)->newInstanceArgs(['plugins' => [ReportContext::class]]),
		InvalidArgumentException::class,
		'Plugin `ReportContext` is not a plugin; a rule is named in `rules` and a preset in `presets`.',
	);
});


test('an override turns a rule off by its decision, an unknown one is an error before any file', function () use ($fixtures) {
	$off = new Config(rules: [ReportContext::class], decisions: ReportsContext, overrides: [new Override(['sub'], new Profile(decisions: ['project' => ['context' => 'keep']]))]);
	$runner = buildRunner($off, "$fixtures/project");
	Assert::same([], $runner->processCode('src/sub/x.php', "<?php\n\$a;\n")->violations);
	Assert::count(1, $runner->processCode('src/x.php', "<?php\n\$a;\n")->violations);

	$unknown = new Config(rules: [ReportContext::class], overrides: [new Override(['sub'], new Profile(decisions: ['project' => ['nope' => 'keep']]))]);
	Assert::exception(
		fn() => (new RunnerFactory)->resolve($unknown, "$fixtures/project"),
		ConfigurationException::class,
		'The override for `sub`: %a%`project.nope`%a%',
	);

	// so is a value no decision takes, which would otherwise wait for a file of the override
	$invalid = new Config(overrides: [new Override(['sub'], new Profile(decisions: ['literals' => ['quotes' => 'triple']]))]);
	Assert::exception(
		fn() => (new RunnerFactory)->resolve($invalid, "$fixtures/project"),
		ConfigurationException::class,
		'The override for `sub`: Key `literals.quotes` does not take `triple`; %a%',
	);
});


test('an override brings its presets, its style, its name resolution and its warnings to its files', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$resolution = $factory->resolve(
		new Config(
			use: [DoubleQuotesPlugin::class],
			rules: [ReportContext::class],
			nameResolution: 'certain',
			overrides: [new Override(['sub'], new Profile(use: ['test/double'], nameResolution: 'uncertain', warnOnly: [ReportContext::class], decisions: ['indentation' => ['unit' => '2 spaces']]))],
			decisions: ReportsContext,
		),
		"$fixtures/project",
	);
	$runner = $factory->createRunner($resolution);
	$describe = fn(string $path) => array_map(
		fn($violation) => "$violation->decision {$violation->severity->name} $violation->message",
		$runner->processCode($path, "<?php\n\$a = 'text';\n")->violations,
	);
	Assert::same(['project.context Error 8.1 "\t""\n"'], $describe('src/x.php'));
	$sub = $describe('src/sub/x.php');
	Assert::count(2, $sub);
	Assert::same('project.context Warning 8.1 "  ""\n"', $sub[0]);
	Assert::match('literals.quotes Error %a%', $sub[1]);

	$guard = DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class;
	$sub = $resolution->resolveFor($runner->findOverridesFor('src/sub/x.php'));
	Assert::same(['certain', true], [$resolution->resolvedConfig->nameResolution, $resolution->resolvedConfig->findRule($guard)?->isActive()]);
	Assert::same(['uncertain', false], [$sub->nameResolution, $sub->findRule($guard)?->isActive()]);
});


test('a runner keeps the configuration it was built from, whatever the factory builds after it', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$runner = $factory->createRunner(
		$factory->resolve(new Config(rules: [ReportContext::class], decisions: ReportsContext, overrides: [new Override(['sub'], new Profile(decisions: ['project' => ['context' => 'keep']]))]), "$fixtures/project"),
	);
	$factory->createRunner($factory->resolve(new Config(rules: [ReportVariable::class], decisions: ReportsVariables), "$fixtures/project"));

	// both processors are built lazily, the base one and the one of the override, and both after the second runner
	Assert::same(['project.context'], array_map(fn($v) => $v->decision, $runner->processCode('src/x.php', "<?php\n\$a;\n")->violations));
	Assert::same([], $runner->processCode('src/sub/x.php', "<?php\n\$a;\n")->violations);
});
