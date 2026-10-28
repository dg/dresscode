<?php declare(strict_types=1);

use Composer\InstalledVersions;
use DressCode\{
	CodeWriter, 	Config, ConfigurationException, Decision, Domain, NodeRule, Override, Plugin, PluginManifest, Profile, RuleContext, RuleInfo, Stage,
};
use DressCode\Config\{ComposerJson, PhpVersionSource, RunnerFactory, UpgradingData};
use DressCode\Engine\{Baseline, Runner, TypeAnalysisStatus};
use DressCode\Reporters\NullReporter;
use DressCode\Rules\ControlFlow\MatchForSwitchRule;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\{Assert, FileMock};

require __DIR__ . '/../../bootstrap.php';

$fixtures = str_replace('\\', '/', __DIR__) . '/fixtures';


/** A runner built by a factory of its own, as every run of the tool builds one. */
function buildRunner(Config $config, string $root, ?Profile $commandLine = null, ?string $configFile = null): Runner
{
	$factory = new RunnerFactory;
	return $factory->createRunner($factory->resolve($config, $root, $commandLine), configFile: $configFile);
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
		$context->report(
			$node,
			$context->phpVersion . ' ' . json_encode($context->style->indent) . json_encode($context->style->lineEnding),
		);
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


#[RuleInfo(Stage::Structure, decisions: ['shared.variables'])]
final class SharingRule extends NodeRule
{
	public function getVisitedNodes(): array
	{
		return [];
	}
}


final class SharedPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			section: 'shared',
			decisions: [new Decision('shared.variables', Domain::state('forbidden'), 'A variable is reported')],
		);
	}
}


final class SharingPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(rules: [SharingRule::class], section: 'sharing', plugins: [SharedPlugin::class]);
	}
}


final class StrangerPlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(rules: [SharingRule::class], section: 'stranger');
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


#[RuleInfo(Stage::Structure)]
final class DependentRule extends NodeRule
{
	public function __construct(
		private readonly string $label,
	) {
	}


	public static function getDecisions(): array
	{
		return [new Decision('dependent.variables', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, "A variable, $this->label.");
	}
}


/** Builds its rule with the service it holds, an instance for every call of the factory. */
final class FactoryPlugin implements Plugin
{
	/** @var list<DependentRule> */
	public array $built = [];


	public function __construct(
		private readonly string $service = 'served',
	) {
	}


	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			rules: [DependentRule::class => fn(): DependentRule => $this->built[] = new DependentRule($this->service)],
			section: 'dependent',
		);
	}
}


test(
	'a plugin is loaded once by its class, given by its name or as an object, the plugins it builds on registered before it',
	function () use ($fixtures) {
		$factory = new RunnerFactory;
		BasePlugin::$manifests = 0;
		$factory->resolve(new Config(use: [new BasePlugin, TopPlugin::class, new BasePlugin]), "$fixtures/project");
		Assert::same(1, BasePlugin::$manifests);
		Assert::same([BaseRule::class, TopRule::class], array_slice($factory->registry->rules, -2));
	},
);


test('a rule of a plugin is built by the factory of its manifest, anew for every combination of overrides', function () {
	$root = createTempDir('runner-factory-plugin-factory');
	mkdir("$root/tests");
	file_put_contents("$root/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/tests/b.php", "<?php\n\$b;\n");
	$plugin = new FactoryPlugin;
	$config = new Config(
		use: [$plugin],
		overrides: [new Override(['tests'], new Profile(targets: ['php' => '8.4']))],
		decisions: ['dependent' => ['variables' => 'forbidden']],
	);
	$result = buildRunner($config, $root)->run(['a.php', 'tests/b.php'], false, new NullReporter);
	Assert::same(
		['A variable, served.', 'A variable, served.'],
		array_map(fn($file) => $file->violations[0]->message, $result->files),
	);
	Assert::count(2, $plugin->built);
	Assert::notSame($plugin->built[0], $plugin->built[1]);
});


test('a rule of a plugin names a tree of a plugin it builds on, never one of a plugin it does not', function () use ($fixtures) {
	$resolution = new RunnerFactory()->resolve(new Config(use: [SharingPlugin::class]), "$fixtures/project");
	Assert::same([SharingRule::class], $resolution->getCatalogue()->getRulesOf('shared.variables'));
	Assert::exception(
		fn() => new RunnerFactory()->resolve(new Config(use: [SharedPlugin::class, StrangerPlugin::class]), "$fixtures/project"),
		ConfigurationException::class,
		'Rule `SharingRule` names `shared.variables` of the plugin of section `shared`, which the plugin of section `stranger` does not build on; add that plugin to the `plugins` of its manifest.',
	);
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
	Assert::same(
		['8.4', PhpVersionSource::Configuration],
		$factory->getPhpTarget(new Config(targets: ['php' => '8.4']), "$fixtures/project"),
	);
	// a directory without a composer.json of its own is answered by the nearest one above it
	Assert::same(['>=8.1 <8.6', PhpVersionSource::Composer], $factory->getPhpTarget(new Config, "$fixtures/project/src"));
	// above the fixtures there is the composer.json of DressCode itself
	Assert::same(PhpVersionSource::Composer, $factory->getPhpTarget(new Config, $fixtures)[1]);
	Assert::same([Config::DefaultPhpVersion, PhpVersionSource::Default], $factory->getPhpTarget(new Config, sys_get_temp_dir()));

	// the version the rules ask about is the lowest of the target
	$resolution = $factory->resolve(new Config, "$fixtures/project");
	Assert::same(['8.1', PhpVersionSource::Composer], [$resolution->resolvedConfig->phpVersion, $resolution->phpVersionSource]);
});


test('a target older than the PHP the rules are tested for stays, with a warning, and the rules of PHP 8 stay off', function () {
	$root = createTempDir('php-floor');
	file_put_contents("$root/composer.json", '{"require": {"php": "^7.4 || ^8.0"}}');
	$warning = 'The target PHP 7.4 is older than 8.0, which DressCode tests; the fixes of PHP 8 constructs stay off.';

	$factory = new RunnerFactory;
	$resolution = $factory->resolve(
		new Config(
			rules: [ReportContext::class, MatchForSwitchRule::class],
			decisions: [...ReportsContext, 'upgrading' => ['syntax' => ['match' => 'required']]],
		),
		$root,
	);
	$runner = $factory->createRunner($resolution, cache: false);
	Assert::same(['7.4', PhpVersionSource::Composer], [$resolution->resolvedConfig->phpVersion, $resolution->phpVersionSource]);
	Assert::same(
		[$warning => null, 'Decision `upgrading.syntax.match` needs PHP >=8.0 and the target is ^7.4 || ^8.0; skipped.' => null],
		$resolution->warnings,
	);
	Assert::match('7.4 %a%', $runner->processCode('x.php', "<?php\n\$a;\n")->violations[0]->message);
	Assert::same(
		'it needs PHP >=8.0 and the target is ^7.4 || ^8.0',
		$resolution->resolvedConfig->findRule(MatchForSwitchRule::class)?->inactiveMessage,
	);

	// every resolution starts with warnings of its own
	$resolution = $factory->resolve(new Config(targets: ['php' => '7.4']), $root);
	Assert::same(['7.4', PhpVersionSource::Configuration], [$resolution->resolvedConfig->phpVersion, $resolution->phpVersionSource]);
	Assert::same([$warning => null], $resolution->warnings);
});


test('the types of the code come from the PHPStan of the project when the configuration says so', function () {
	$root = createTempDir('types');
	mkdir("$root/stubs");
	copy(__DIR__ . '/../Analyses/fixtures/types/stubs/Order.php', "$root/stubs/Order.php");
	// a file that declares a class, which is what makes PHPStan read it from the disk
	$code = <<<'XX'
		<?php

		use Acme\Shop\Order;

		class Check
		{
			public function run(Order $order): string
			{
				return $order::STATUS_PAID;
			}
		}
		XX;
	file_put_contents("$root/Check.php", $code);

	$factory = new RunnerFactory;
	$runner = $factory->createRunner(
		$factory->resolve(
			new Config(
				paths: ['stubs'],
				typeAnalysis: 'phpstan',
				decisions: ['upgrading' => ['declarations' => ['deprecatedMember' => 'forbidden']]],
			),
			$root,
		),
		cache: false,
	);
	// the run names the file relative to the root, while the working directory is another
	$result = $runner->processCode("$root/Check.php", $code);
	Assert::same(
		['9: Constant `Acme\Shop\Order::STATUS_PAID` is deprecated: use Order::StatusPaid.'],
		array_map(fn($violation) => "$violation->line: $violation->message", $result->violations),
	);

	// a member the map of replacedMembers has is left to that rule, the deprecation being the fallback of a library without data
	$upgrading = [
		'declarations' => ['deprecatedMember' => 'forbidden'],
		'api' => ['replacedMembers' => ['Acme\Shop\Order::STATUS_PAID' => 'StatusPaid']],
	];
	$runner = $factory->createRunner(
		$factory->resolve(new Config(paths: ['stubs'], typeAnalysis: 'phpstan', decisions: ['upgrading' => $upgrading]), $root),
		cache: false,
	);
	Assert::same(
		['9: Constant `Acme\Shop\Order::STATUS_PAID` is replaced by `Order::StatusPaid`.'],
		array_map(fn($violation) => "$violation->line: $violation->message", $runner->processCode("$root/Check.php", $code)->violations),
	);

	// without the types a decision the project makes is refused, not left out
	Assert::exception(
		fn() => $factory->resolve(new Config(decisions: ['upgrading' => ['declarations' => ['deprecatedMember' => 'forbidden']]]), $root),
		ConfigurationException::class,
		'Decision `upgrading.declarations.deprecatedMember` needs the types of the code; %a%',
	);
});


test('a configuration of PHPStan it cannot start with is a warning of the run, and the types go without it', function () {
	$root = createTempDir('types-broken-config');
	mkdir("$root/stubs");
	copy(__DIR__ . '/../Analyses/fixtures/types/stubs/Order.php', "$root/stubs/Order.php");
	// an extension the project loads from its own vendor directory, which another installation does not have
	file_put_contents("$root/phpstan.neon", "includes:\n\t- vendor/acme/phpstan-rules/extension.neon\n");
	file_put_contents(
		"$root/Check.php",
		"<?php\n\nfunction check(Acme\\Shop\\Order \$order): string\n{\n\treturn \$order::STATUS_PAID;\n}\n",
	);

	$factory = new RunnerFactory;
	$config = new Config(
		paths: ['Check.php', 'stubs'],
		typeAnalysis: 'phpstan',
		decisions: ['upgrading' => ['declarations' => ['deprecatedMember' => 'forbidden']]],
	);
	$runner = $factory->createRunner($factory->resolve($config, $root), cache: false);
	$result = $runner->run(['Check.php'], false, new NullReporter);
	Assert::same(['5: upgrading.declarations.deprecatedMember'], array_map(
		fn($violation) => "$violation->line: $violation->decision",
		$result->files[0]->violations,
	));
	Assert::match('PHPStan could not start with `phpstan.neon` of the project: %a%; the types go without it.', $result->warnings[0] ?? '');
});


test('types without PHPStan beside DressCode are a warning, and the run goes without them', function () {
	$root = createTempDir('types-missing');
	// a preset may ask for what the run cannot give, the project may not
	file_put_contents("$root/preset.neon", "upgrading:\n\tdeclarations:\n\t\tdeprecatedMember: forbidden\n");
	$factory = new RunnerFactory(phpstanInstalled: false);
	$resolution = $factory->resolve(new Config(use: ["$root/preset.neon"], typeAnalysis: 'phpstan'), $root);
	Assert::null($resolution->resolvedConfig->typeAnalysis);
	Assert::same(
		'it needs the types of the code and phpstan/phpstan is not installed beside DressCode',
		$resolution->resolvedConfig->findRule(DressCode\Rules\Upgrading\NoDeprecatedMembersRule::class)?->inactiveMessage,
	);
	Assert::same(
		['The configuration sets `typeAnalysis: phpstan`, but `phpstan/phpstan` is not installed beside DressCode, so the run goes without the types of the code.' => 'types#enable'],
		$resolution->warnings,
	);
	Assert::same(
		TypeAnalysisStatus::Unavailable,
		$factory->createRunner($resolution, cache: false)->createRunInfo(fix: false, fileCount: 0)->typeAnalysis,
	);

	// a decision the project makes cannot be left out, so it is refused
	Assert::exception(
		fn() => $factory->resolve(
			new Config(typeAnalysis: 'phpstan', decisions: ['upgrading' => ['declarations' => ['deprecatedMember' => 'forbidden']]]),
			$root,
		),
		ConfigurationException::class,
		'Decision `upgrading.declarations.deprecatedMember` needs the types of the code, but `phpstan/phpstan` is not installed beside DressCode.',
	);
});


test('the run tells the reporter a rule did not run without the types only where the configuration turns it on', function () {
	$root = createTempDir('types-turned-on');
	$factory = new RunnerFactory(phpstanInstalled: true);
	$missing = fn(Config $config) => $factory->createRunner($factory->resolve($config, $root), cache: false)
		->createRunInfo(fix: false, fileCount: 0)
		->typesMissing;

	Assert::true($missing(new Config(use: ['deprecations'])));
	Assert::false($missing(new Config(use: ['deprecations'], typeAnalysis: 'phpstan')));
	Assert::false($missing(new Config(use: ['psr12'])));
	// a decision kept is not one the run misses
	$resolution = $factory->resolve(
		new Config(use: ['deprecations'], decisions: ['upgrading' => ['declarations' => ['deprecatedMember' => 'keep']]]),
		$root,
	);
	Assert::same(
		DressCode\Config\InactiveReason::TurnedOff,
		$resolution->resolvedConfig->findRule(DressCode\Rules\Upgrading\NoDeprecatedMembersRule::class)?->inactiveReason,
	);
});


test('what the data of PHP say is reported under the requirement that lays them, without the types too', function () {
	$root = createTempDir('php-data');
	$factory = new RunnerFactory(phpstanInstalled: false);
	$upgrading = ['php' => ['deprecatedApi' => 'forbidden'], 'api' => ['forbiddenFunctions' => ['acme_retired' => 'gone']]];
	$code = "<?php\nstrftime('%Y');\nacme_retired();\n";
	$decisions = fn(?string $only) => array_map(
		fn($violation) => "$violation->line: $violation->decision",
		$factory->createRunner(
			$factory->resolve(new Config(decisions: ['upgrading' => $upgrading]), $root, only: $only === null ? null : [$only]),
			cache: false,
		)
			->processCode("$root/Check.php", $code)
			->violations,
	);

	Assert::same(['2: upgrading.php.deprecatedApi', '3: upgrading.api.forbiddenFunctions'], $decisions(null));
	Assert::same(['2: upgrading.php.deprecatedApi'], $decisions('upgrading.php.deprecatedApi'));
	// the package of the data stands for that requirement too, beside the maps it writes into, which the project's entries share
	Assert::same(['2: upgrading.php.deprecatedApi', '3: upgrading.api.forbiddenFunctions'], $decisions('php'));
});


test('every rule the data of PHP feed reports what they say under the requirement that lays them', function () {
	$root = createTempDir('php-data-types');
	$code = <<<'XX'
		<?php

		function checkPhpData(SplObjectStorage $storage, object $item, ReflectionProperty $property): string
		{
			$storage->detach($item);
			$property->setAccessible(true);
			return utf8_encode('a');
		}
		XX;
	file_put_contents("$root/Check.php", $code);
	$factory = new RunnerFactory;
	$decisions = function (string $php) use ($factory, $root, $code): array {
		$config = new Config(
			typeAnalysis: 'phpstan',
			targets: ['php' => $php],
			decisions: ['upgrading' => ['php' => ['deprecatedApi' => 'forbidden']]],
		);
		return array_map(
			fn($violation) => "$violation->line: $violation->decision",
			$factory->createRunner($factory->resolve($config, $root), cache: false)->processCode("$root/Check.php", $code)->violations,
		);
	};

	// the call PHP 8.1 made a no-op is forbidden where the target still has 8.0, and removed from 8.1 on
	$expected = ['5: upgrading.php.deprecatedApi', '6: upgrading.php.deprecatedApi', '7: upgrading.php.deprecatedApi'];
	Assert::same($expected, $decisions('8.0'));
	Assert::same($expected, $decisions('8.1'));
});


test('a violation of an entry of upgrading data names its section and the version its fix is right from', function () {
	$root = createTempDir('data-source');
	$factory = new RunnerFactory(phpstanInstalled: false);
	$upgrading = ['php' => ['deprecatedApi' => 'forbidden'], 'api' => ['forbiddenFunctions' => ['acme_retired' => 'gone']]];
	$config = new Config(targets: ['php' => '8.0'], decisions: ['upgrading' => $upgrading]);
	$code = "<?php\nmysqli_execute(\$statement);\ncurl_close(\$handle);\nacme_retired();\n";
	$violations = $factory->createRunner($factory->resolve($config, $root), cache: false)
		->processCode("$root/Check.php", $code)
		->violations;

	// an entry the project wrote has none
	Assert::same(
		[['php.neon', 'php', '8.5', null], ['php.neon', 'php', '8.5', '8.0'], null],
		array_map(
			fn($violation) => $violation->source === null
				? null
				: [$violation->source->name, $violation->source->library, $violation->source->since, $violation->source->from],
			$violations,
		),
	);
});


test('the targets of the command line cut the upgrading data before they are laid, over those of the configuration', function () {
	$root = createTempDir('targets-command');
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0"}}');
	$code = <<<'XX'
		<?php

		function checkTargets(ReflectionProperty $property): void
		{
			$property->setAccessible(true);
		}
		XX;
	file_put_contents("$root/Check.php", $code);
	$factory = new RunnerFactory;
	$config = new Config(typeAnalysis: 'phpstan', decisions: ['upgrading' => ['php' => ['deprecatedApi' => 'forbidden']]]);
	$run = function (?Config $commandLine) use ($factory, $config, $root, $code): array {
		$resolution = $factory->resolve($config, $root, $commandLine);
		$php = array_find(array_column($resolution->upgradingData, 0), fn(UpgradingData $data) => $data->package === 'php');
		$result = $factory->createRunner($resolution, cache: false)->processCode("$root/Check.php", $code);
		return [$resolution->resolvedConfig->phpVersion, $php?->unreached[0], $result->violations[0]->fixable];
	};

	// what PHP 8.1 made a no-op is reported for code that runs on 8.0, and removed once the command line moves it to 8.1
	Assert::same(['8.0', '8.1', false], $run(null));
	Assert::same(['8.1', '8.3', true], $run(new Config(targets: ['php' => '8.1'])));

	$resolution = $factory->resolve(new Config(targets: ['acme/mail' => '2.0']), $root, new Config(targets: ['acme/mail' => '3.0']));
	Assert::same(['acme/mail' => '3.0'], $resolution->resolvedConfig->packageTargets);
});


test('the command line asks for the types without a configuration that sets them, and PHPStan missing refuses the run', function () {
	$root = createTempDir('types-command');
	$commandLine = new Config(use: ['deprecations'], typeAnalysis: 'phpstan');
	$resolution = new RunnerFactory(phpstanInstalled: true)->resolve(new Config, $root, $commandLine);
	Assert::same('phpstan', $resolution->resolvedConfig->typeAnalysis);
	Assert::true($resolution->resolvedConfig->findRule(DressCode\Rules\Upgrading\NoDeprecatedMembersRule::class)?->isActive());

	Assert::exception(
		fn() => new RunnerFactory(phpstanInstalled: false)->resolve(new Config, $root, $commandLine),
		ConfigurationException::class,
		'The command needs the types of the code, but `phpstan/phpstan` is not installed beside DressCode.',
	);
});


test('the identity of the process names the packages it is loaded from, not its root, nor what a phar brings', function () {
	$own = require __DIR__ . '/../../../vendor/composer/installed.php';
	$identity = RunnerFactory::getProcessIdentity();
	Assert::true(isset($identity['nette/utils']));
	Assert::false(isset($identity[$own['root']['name']]));

	// a global installation beside the own one, with a package that is only provided
	$global = fn(string $vendor) => [
		'root' => [
			'name' => 'acme/global', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project',
			'install_path' => "$vendor/..", 'aliases' => [], 'dev' => false,
		],
		'versions' => [
			'acme/global' => [
				'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project',
				'install_path' => "$vendor/..", 'aliases' => [], 'dev_requirement' => false,
			],
			'acme/linter' => [
				'pretty_version' => '2.1.0', 'version' => '2.1.0.0', 'reference' => 'abc123', 'type' => 'library',
				'install_path' => "$vendor/acme/linter", 'aliases' => [], 'dev_requirement' => false,
			],
			'acme/virtual' => ['dev_requirement' => false, 'provided' => ['1.0']],
		],
	];
	try {
		InstalledVersions::reload($global('/home/user/.composer/vendor'));
		$identity = RunnerFactory::getProcessIdentity();
		Assert::same(['2.1.0.0', 'abc123'], $identity['acme/linter']);
		Assert::false(isset($identity['acme/global']));
		Assert::false(isset($identity['acme/virtual']));

		InstalledVersions::reload($global('phar://linter.phar/vendor'));
		Assert::false(isset(RunnerFactory::getProcessIdentity()['acme/linter']));
	} finally {
		InstalledVersions::reload($own);
	}
});


test('the constraint of require.php from the version of config.platform.php on', function () use ($fixtures) {
	$detect = fn(string $json) => ComposerJson::detectPhpTarget(FileMock::create($json, 'json'));
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

	// the platform the packages are resolved for raises the lower bound, an upper one staying
	Assert::same('^8.1, >=8.3', $detect('{"require": {"php": "^8.1"}, "config": {"platform": {"php": "8.3"}}}'));
	Assert::same('<8.6, >=8.3.4', $detect('{"require": {"php": "<8.6"}, "config": {"platform": {"php": "8.3.4"}}}'));
	Assert::same(
		'^8.1, >=8.3 || ^9.0, >=8.3',
		$detect('{"require": {"php": "^8.1 || ^9.0"}, "config": {"platform": {"php": "8.3"}}}'),
	);
	Assert::same('>=8.3', $detect('{"config": {"platform": {"php": "8.3"}}}'));
	Assert::same('^8.1', $detect('{"require": {"php": "^8.1"}, "config": {"platform": {"php": false}}}'));
	Assert::null(ComposerJson::detectPhpTarget("$fixtures/none.json"));
	Assert::null(ComposerJson::detectPhpTarget(null));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$runner = buildRunner(
		new Config(
			rules: [ReportContext::class],
			excludePaths: ['sub'],
			decisions: ['indentation' => ['unit' => '2 spaces']] + ReportsContext,
		),
		"$fixtures/project",
	);
	Assert::same([], $runner->findFiles(['src']));
	$result = $runner->processCode('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$runner = buildRunner(
		new Config(
			rules: [ReportContext::class],
			decisions: ['indentation' => ['unit' => '2 spaces'], 'file' => ['lineEnding' => 'LF']] + ReportsContext,
		),
		"$fixtures/project",
	);
	Assert::same(
		['Expected LF line endings, CRLF found.', '8.1 "  ""\n"'],
		array_map(fn($v) => $v->message, $runner->processCode('x.php', "<?php\r\n\$a;\r\n")->violations),
	);
});


test('the command line lies over the configuration', function () use ($fixtures) {
	$runner = buildRunner(
		new Config(rules: [ReportContext::class, ReportVariable::class], decisions: ReportsContext),
		"$fixtures/project",
		commandLine: new Profile(decisions: ['project' => ['context' => 'keep', 'variables' => 'forbidden']]),
	);
	Assert::same(['project.variables'], array_map(fn($v) => $v->decision, $runner->processCode('x.php', "<?php\n\$a;\n")->violations));
});


test(
	'a plugin makes the decisions of its rules known, and brings the paths it leaves out and the files it skips',
	function () use ($fixtures) {
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
		$runner = $factory->createRunner($resolution, cache: false);
		Assert::same([PluginRule::class], array_map(fn($rule) => $rule->class, $resolution->resolvedConfig->getActiveRules()));

		// the paths of every layer add up, and a file any layer skips is skipped
		$files = $runner->findFiles(['.']);
		Assert::same(['checked.php', 'generated.php', 'skipped.php'], $files);
		Assert::same(['checked.php'], array_map(fn($result) => $result->path, $runner->run($files, false, new NullReporter)->files));
	},
);


test(
	'the page of a rule is where the configuration that names it by class says, or the plugin that brings it',
	function () use ($fixtures) {
		$factory = new RunnerFactory;
		$factory->resolve(new Config(rules: [ReportContext::class], ruleUrl: 'https://wiki.acme.dev/{slug}'), "$fixtures/project");
		Assert::same('https://wiki.acme.dev/reportContext', $factory->registry->findRuleUrl(ReportContext::class));
		Assert::same(
			'https://dresscode.run/decisions/functions.trailingBareReturn',
			$factory->registry->findUrl('functions.trailingBareReturn'),
		);

		$factory = new RunnerFactory;
		$factory->resolve(new Config(use: [ProjectPlugin::class], ruleUrl: 'https://wiki.acme.dev/{slug}'), "$fixtures/project");
		Assert::null($factory->registry->findRuleUrl(PluginRule::class));
	},
);


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
	$off = new Config(
		rules: [ReportContext::class],
		decisions: ReportsContext,
		overrides: [new Override(['sub'], new Profile(decisions: ['project' => ['context' => 'keep']]))],
	);
	$runner = buildRunner($off, "$fixtures/project");
	Assert::same([], $runner->processCode('src/sub/x.php', "<?php\n\$a;\n")->violations);
	Assert::count(1, $runner->processCode('src/x.php', "<?php\n\$a;\n")->violations);

	$unknown = new Config(
		rules: [ReportContext::class],
		overrides: [new Override(['sub'], new Profile(decisions: ['project' => ['nope' => 'keep']]))],
	);
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
			overrides: [new Override(
				['sub'],
				new Profile(
					use: ['test/double'],
					nameResolution: 'uncertain',
					severity: [ReportContext::class => 'warning'],
					decisions: ['indentation' => ['unit' => '2 spaces']],
				),
			)],
			decisions: ReportsContext,
		),
		"$fixtures/project",
	);
	$runner = $factory->createRunner($resolution, cache: false);
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
	Assert::same(
		['certain', true],
		[$resolution->resolvedConfig->nameResolution, $resolution->resolvedConfig->findRule($guard)?->isActive()],
	);
	Assert::same(['uncertain', false], [$sub->nameResolution, $sub->findRule($guard)?->isActive()]);
});


test('a runner keeps the configuration it was built from, whatever the factory builds after it', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$runner = $factory->createRunner(
		$factory->resolve(
			new Config(
				rules: [ReportContext::class],
				decisions: ReportsContext,
				overrides: [new Override(['sub'], new Profile(decisions: ['project' => ['context' => 'keep']]))],
			),
			"$fixtures/project",
		),
		cache: false,
	);
	$factory->createRunner(
		$factory->resolve(new Config(rules: [ReportVariable::class], decisions: ReportsVariables), "$fixtures/project"),
		cache: false,
	);

	// both processors are built lazily, the base one and the one of the override, and both after the second runner
	Assert::same(['project.context'], array_map(fn($v) => $v->decision, $runner->processCode('src/x.php', "<?php\n\$a;\n")->violations));
	Assert::same([], $runner->processCode('src/sub/x.php', "<?php\n\$a;\n")->violations);
});


test('a rule built by a closure is cached only under the text of the file the configuration came from', function () {
	$root = createTempDir('runner-factory');
	file_put_contents("$root/x.php", "<?php\n\$x;\n");
	file_put_contents("$root/quiet.php", '<?php // quiet');
	file_put_contents("$root/loud.php", '<?php // loud');
	$quiet = fn() => new Config(
		rules: [ReportVariable::class => fn() => new ReportVariable(report: false)],
		cacheDir: "$root/cache",
		decisions: ReportsVariables,
	);
	$loud = fn() => new Config(
		rules: [ReportVariable::class => fn() => new ReportVariable],
		cacheDir: "$root/cache",
		decisions: ReportsVariables,
	);
	$run = fn(Config $config, ?string $file = null) => buildRunner($config, $root, configFile: $file)
		->run(['x.php'], false, new NullReporter);

	// without the file nothing tells the two closures apart, so nothing is cached
	Assert::same(0, $run($quiet())->countViolations());
	Assert::same(0, $run($quiet())->countViolations());
	Assert::same(1, $run($loud())->countViolations());

	// with it the text of the file is part of the identity
	Assert::false($run($quiet(), "$root/quiet.php")->files[0]->cached);
	Assert::true($run($quiet(), "$root/quiet.php")->files[0]->cached);
	Assert::same(1, $run($loud(), "$root/loud.php")->countViolations());
});


test('the decisions of the command line are part of the identity, whatever the file of the configuration says', function () {
	$root = createTempDir('runner-factory-options');
	file_put_contents("$root/x.php", "<?php \$x = \\strlen('a');\n");
	file_put_contents("$root/config.php", '<?php // the same text for both runs');
	$config = fn(string $cacheDir) => new Config(
		cacheDir: $cacheDir,
		decisions: ['qualification' => ['leadingBackslash' => 'forbidden']],
	);
	$run = fn(?Profile $commandLine, string $cacheDir) => buildRunner($config($cacheDir), $root, $commandLine, "$root/config.php")
		->run(['x.php'], false, new NullReporter);

	// the command line changes the decisions, not the text of the file
	foreach ([[false, true], [true, false]] as $order) {
		$cacheDir = createTempDir('cache');
		foreach ($order as $tight) {
			$result = $run($tight ? null : new Profile(decisions: ['qualification' => ['leadingBackslash' => 'keep']]), $cacheDir);
			Assert::same($tight ? 1 : 0, $result->countViolations());
			Assert::false($result->files[0]->cached);
		}
	}
});


test('what an override comes to is part of the identity, a preset only it uses included', function () {
	$root = createTempDir('runner-factory-override');
	file_put_contents("$root/x.php", "<?php\n\$x;\n");
	file_put_contents("$root/config.php", '<?php // the same text for both runs');
	file_put_contents("$root/style.neon", "file:\n\tstrictTypes:\n\t\tdeclaration: keep\n");
	$run = fn() => buildRunner(
		new Config(cacheDir: "$root/cache", overrides: [new Override(['x.php'], new Profile(use: ["$root/style.neon"]))]),
		$root,
		configFile: "$root/config.php",
	)->run(['x.php'], false, new NullReporter);

	Assert::same(0, $run()->countViolations());
	Assert::true($run()->files[0]->cached);
	file_put_contents("$root/style.neon", "file:\n\tstrictTypes:\n\t\tdeclaration: required\n");
	$result = $run();
	Assert::false($result->files[0]->cached);
	Assert::same(1, $result->countViolations());
});


test('a rule of the project itself is part of the identity by the time its file changed', function () {
	$root = createTempDir('runner-factory-sources');
	$file = "$root/TouchedRule.php";
	if (!class_exists('TouchedRule', autoload: false)) {
		file_put_contents(
			$file,
			"<?php\n#[DressCode\\RuleInfo(DressCode\\Stage::Structure)]\nfinal class TouchedRule extends DressCode\\NodeRule\n{\n\tuse ProjectDecision;\n\n\tpublic function getVisitedNodes(): array\n\t{\n\t\treturn [];\n\t}\n}\n",
		);
		require $file;
	}

	file_put_contents("$root/x.php", "<?php\n");
	$cached = fn() => buildRunner(new Config(rules: ['TouchedRule'], cacheDir: "$root/cache"), $root) // @phpstan-ignore argument.type (the class is declared at run time)
		->run(['x.php'], false, new NullReporter)
		->files[0]->cached;

	Assert::false($cached());
	Assert::true($cached());
	touch($file, (int) filemtime($file) + 10);
	clearstatcache();
	Assert::false($cached());
	Assert::true($cached());
});


test('a file changed in the second the run began is part of the identity by its text, the time and size alike', function () {
	$root = createTempDir('runner-factory-same-second');
	$file = "$root/SameSecondRule.php";
	$source = fn(string $comment) => "<?php\n// $comment\n#[DressCode\\RuleInfo(DressCode\\Stage::Structure)]\nfinal class SameSecondRule extends DressCode\\NodeRule\n{\n\tuse ProjectDecision;\n\n\tpublic function getVisitedNodes(): array\n\t{\n\t\treturn [];\n\t}\n}\n";
	file_put_contents($file, $source('first'));
	if (!class_exists('SameSecondRule', autoload: false)) {
		require $file;
	}

	file_put_contents("$root/x.php", "<?php\n");
	$cached = fn() => buildRunner(new Config(rules: ['SameSecondRule'], cacheDir: "$root/cache"), $root) // @phpstan-ignore argument.type (the class is declared at run time)
		->run(['x.php'], false, new NullReporter)
		->files[0]->cached;

	$time = time();
	touch($file, $time);
	clearstatcache();
	Assert::false($cached());
	file_put_contents($file, $source('other'));
	touch($file, $time);
	clearstatcache();
	Assert::false($cached());

	// with the types, a declaration of the project changed alike
	mkdir("$root/src");
	file_put_contents("$root/src/Order.php", "<?php\nnamespace Acme\\Shop;\n\nclass Order\n{\n\tpublic const Paid = 'a';\n}\n");
	$cached = fn() => buildRunner(
		new Config(
			paths: ['src'],
			typeAnalysis: 'phpstan',
			cacheDir: "$root/cache",
			decisions: ['upgrading' => ['declarations' => ['deprecatedMember' => 'forbidden']]],
		),
		$root,
	)->run(['x.php'], false, new NullReporter)->files[0]->cached;
	touch("$root/src/Order.php", $time);
	clearstatcache();
	Assert::false($cached());
	file_put_contents("$root/src/Order.php", "<?php\nnamespace Acme\\Shop;\n\nclass Order\n{\n\tpublic const Paid = 'b';\n}\n");
	touch("$root/src/Order.php", $time);
	clearstatcache();
	Assert::false($cached());
});


test('a stub the configuration of PHPStan reads outside the analysed paths drops the cache once it changes', function () {
	$root = createTempDir('runner-factory-stub');
	mkdir("$root/src");
	mkdir("$root/stubs");
	file_put_contents("$root/phpstan.neon", "parameters:\n\tstubFiles:\n\t\t- stubs/Order.stub\n");
	$stub = "$root/stubs/Order.stub";
	file_put_contents($stub, "<?php\nnamespace Acme\\Shop;\n\nclass Order\n{\n\tpublic const Paid = 'a';\n}\n");
	touch($stub, time() - 20);
	file_put_contents("$root/x.php", "<?php\n\$a = \\Acme\\Shop\\Order::Paid;\n");
	// the types are an analysis built by a closure, cached only under a file of the configuration
	file_put_contents("$root/dresscode.neon", '');
	$cached = fn() => buildRunner(
		new Config(
			paths: ['src'],
			typeAnalysis: 'phpstan',
			cacheDir: "$root/cache",
			decisions: ['upgrading' => ['declarations' => ['deprecatedMember' => 'forbidden']]],
		),
		$root,
		configFile: "$root/dresscode.neon",
	)->run(['x.php'], false, new NullReporter)->files[0]->cached;

	Assert::false($cached());
	Assert::true($cached());
	file_put_contents($stub, "<?php\nnamespace Acme\\Shop;\n\nclass Order\n{\n\tpublic const Paid = 'bb';\n}\n");
	touch($stub, time() - 10);
	clearstatcache();
	Assert::false($cached());
	Assert::true($cached());
});


test('a helper of the tool the rules stand on is part of the identity by the time its file changed', function () {
	$root = createTempDir('runner-factory-helpers');
	file_put_contents("$root/x.php", "<?php\n");
	$cached = fn() => buildRunner(new Config(rules: [ReportVariable::class], cacheDir: "$root/cache", decisions: ReportsVariables), $root)
		->run(['x.php'], false, new NullReporter)
		->files[0]->cached;

	$file = (string) new ReflectionClass(CodeWriter::class)->getFileName();
	$time = (int) filemtime($file);
	Assert::false($cached());
	Assert::true($cached());
	try {
		touch($file, $time + 10);
		clearstatcache();
		Assert::false($cached());
	} finally {
		touch($file, $time);
		clearstatcache();
	}

	// so are the data the rules read from the tree, the upgrading data of PHP among them
	$file = DressCode\Config\PackageDiscovery::PhpFile;
	$time = (int) filemtime($file);
	$cached();
	Assert::true($cached());
	try {
		touch($file, $time + 10);
		clearstatcache();
		Assert::false($cached());
	} finally {
		touch($file, $time);
		clearstatcache();
	}
});


test('a package the project upgrades is part of the identity, however the tool itself was installed', function () {
	$root = createTempDir('runner-factory-packages');
	mkdir("$root/vendor/composer", recursive: true);
	file_put_contents("$root/composer.json", '{"name": "app/project", "require": {"acme/lib": "^3.1"}}');
	file_put_contents("$root/x.php", "<?php\n");
	$installed = fn(string $reference) => file_put_contents(
		"$root/vendor/composer/installed.json",
		json_encode(['packages' => [[
			'name' => 'acme/lib',
			'version' => 'dev-master',
			'version_normalized' => 'dev-master',
			'source' => ['reference' => $reference],
		]]], JSON_THROW_ON_ERROR),
	);
	$cached = fn() => buildRunner(new Config(cacheDir: "$root/cache", decisions: ['file' => ['bom' => 'forbidden']]), $root)
		->run(['x.php'], false, new NullReporter)
		->files[0]->cached;

	$installed('aaaaaaa');
	Assert::false($cached());
	Assert::true($cached());

	// the same version of the same branch, another commit: the files are other files
	$installed('bbbbbbb');
	Assert::false($cached());
	Assert::true($cached());
});


test('a baseline the configuration names must exist, and its name is judged first', function () use ($fixtures) {
	Assert::null(Baseline::open(new Config, $fixtures));
	Assert::exception(
		fn() => Baseline::open(new Config(baseline: 'baseline.neon'), $fixtures),
		ConfigurationException::class,
		'Baseline file `%a%baseline.neon` does not exist; `dresscode baseline` writes it.',
	);
	Assert::exception(
		fn() => Baseline::open(new Config(baseline: 'baseline.txt'), $fixtures),
		ConfigurationException::class,
		'Baseline file `%a%baseline.txt` must be a `.neon` or a `.php` file.',
	);
});
