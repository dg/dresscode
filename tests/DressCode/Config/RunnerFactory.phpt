<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\PhpVersionSource;
use DressCode\Config\RunnerFactory;
use DressCode\ConfigurationException;
use DressCode\Extension;
use DressCode\NodeRule;
use DressCode\Override;
use DressCode\Preset;
use DressCode\PresetInfo;
use DressCode\Profile;
use DressCode\Reporters\NullReporter;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\Literals\StringQuotesRule;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;
use Tester\Assert;
use Tester\FileMock;

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
		$context->report($node, $context->getPhpVersion() . ' ' . json_encode($context->getStyle()->indent) . json_encode($context->getStyle()->eol));
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


final class ProjectExtension implements Extension
{
	public function getConfig(): Config
	{
		return new Config(
			extensions: [ReportContext::class, new NestedExtension],
			skipWhen: fn(string $content) => str_contains($content, '@generated'),
		);
	}
}


final class NestedExtension implements Extension
{
	public function getConfig(): Config
	{
		return new Config(excludePaths: ['sub']);
	}
}


final class DecidingExtension implements Extension
{
	public function getConfig(): Config
	{
		return new Config(presets: ['per']);
	}
}


test('the PHP version comes from the configuration, composer.json or the default', function () use ($fixtures) {
	$factory = new RunnerFactory;
	Assert::same(['8.1', PhpVersionSource::Composer], $factory->resolvePhpVersion(new Config, "$fixtures/project"));
	Assert::same(['8.4', PhpVersionSource::Configuration], $factory->resolvePhpVersion(new Config(php: '8.4'), "$fixtures/project"));
	// a directory without a composer.json of its own is answered by the nearest one above it
	Assert::same(['8.1', PhpVersionSource::Composer], $factory->resolvePhpVersion(new Config, "$fixtures/project/src"));
	// above the fixtures there is the composer.json of DressCode itself
	Assert::same(PhpVersionSource::Composer, $factory->resolvePhpVersion(new Config, $fixtures)[1]);
	Assert::same([Config::DefaultPhpVersion, PhpVersionSource::Default], $factory->resolvePhpVersion(new Config, sys_get_temp_dir()));
});


test('a target older than the oldest PHP DressCode fixes code for is raised to it with a warning', function () {
	$root = __DIR__ . '/../../temp/php-floor';
	@mkdir($root, recursive: true); // @ - may exist
	file_put_contents("$root/composer.json", '{"require": {"php": "^7.4 || ^8.0"}}');
	$warning = 'The target PHP 7.4 is older than PHP 8.0, the oldest DressCode fixes code for; the code is checked as PHP 8.0, so a fix may write syntax the target does not have.';

	$factory = new RunnerFactory;
	$runner = $factory->createRunner(new Config(rules: [ReportContext::class => true]), $root, cache: false);
	Assert::same(['8.0', PhpVersionSource::Composer], $factory->getPhpVersion());
	Assert::same([$warning], $factory->getWarnings());
	Assert::match('8.0 %a%', $runner->processFile('x.php', "<?php\n\$a;\n")->violations[0]->message);

	// every engine the factory builds starts with warnings of its own
	$factory->createRunner(new Config(php: '7.4'), $root, cache: false);
	Assert::same(['8.0', PhpVersionSource::Configuration], $factory->getPhpVersion());
	Assert::same([$warning], $factory->getWarnings());
});


test('the types of the code come from the PHPStan of the project when the configuration says so', function () {
	$root = __DIR__ . '/../../temp/types';
	@mkdir("$root/stubs", recursive: true); // @ - may exist
	copy(__DIR__ . '/../Analyses/fixtures/types/stubs/Form.php', "$root/stubs/Form.php");
	// a file that declares a class, which is what makes PHPStan read it from the disk
	$code = <<<'XX'
		<?php

		use Nette\Forms\Form;

		class Check
		{
			public function run(Form $form): string
			{
				return $form::FILLED;
			}
		}
		XX;
	file_put_contents("$root/Check.php", $code);

	$factory = new RunnerFactory;
	$runner = $factory->createRunner(new Config(rules: ['no-deprecated-members' => true], paths: ['stubs'], types: 'phpstan'), $root, cache: false);
	// the run names the file relative to the root, while the working directory is another
	$result = $runner->processFile("$root/Check.php", $code);
	Assert::same(
		['9: Constant Nette\Forms\Form::FILLED is deprecated: use Form::Filled'],
		array_map(fn($violation) => "$violation->line: $violation->message", $result->violations),
	);

	// without the types the rule the project names is refused, not left out
	Assert::exception(
		fn() => $factory->createRunner(new Config(rules: ['no-deprecated-members' => true]), $root, cache: false),
		ConfigurationException::class,
		'Rule dresscode/no-deprecated-members needs the types of the code: %a%',
	);
});


test('the lowest version the constraint of require.php allows', function () use ($fixtures) {
	$detect = fn(string $json) => RunnerFactory::detectPhpVersion(FileMock::create($json, 'json'));
	Assert::same('8.2', $detect('{"require": {"php": "8.2 - 8.5"}}'));
	Assert::same('8.1', $detect('{"require": {"php": ">=8.1 <8.6"}}'));
	Assert::same('7.4', $detect('{"require": {"php": "^7.4 || ^8.0"}}'));
	Assert::same('8.0', $detect('{"require": {"php": "^8"}}'));
	Assert::same('8.0', $detect('{"require": {"php": "^8.4 || ^8.0"}}')); // the lowest of the alternatives, not the first
	Assert::same('7.4', $detect('{"require": {"php": "~7.4.0|~8.0.0"}}'));
	Assert::same('8.0', $detect('{"require": {"php": ">=8.0.2, <8.0.15"}}'));
	Assert::same('8.1', $detect('{"require": {"php": ">= 8.1"}}'));
	Assert::same('8.2', $detect('{"require": {"php": "8.2.*"}}'));
	Assert::same('8.3', $detect('{"require": {"php": "8.3.*|8.4.*"}}'));
	Assert::same('8.2', $detect('{"require": {"php": "8.2"}}'));
	Assert::same('8.0', $detect('{"require": {"php": ">8.0"}}')); // 8.0.1 is allowed
	Assert::same('5.6', $detect('{"require": {"php": ">5.6"}}'));
	Assert::same('8.0', $detect('{"require": {"php": "!=8.1 >=8.0"}}'));
	// a constraint without a lower bound says nothing, not the first number it names
	Assert::null($detect('{"require": {"php": "<8.4"}}'));
	Assert::null($detect('{"require": {"php": "not a constraint"}}'));
	Assert::null($detect('{"require": {"php": ""}}'));
	Assert::null($detect('{"require": {"php": "*"}}'));
	Assert::null($detect('{"require": {}}'));
	Assert::null($detect('not json'));
	Assert::null(RunnerFactory::detectPhpVersion("$fixtures/none.json"));
	Assert::null(RunnerFactory::detectPhpVersion(null));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$runner = (new RunnerFactory)->createRunner(new Config(rules: [ReportContext::class => true], indent: 2, excludePaths: ['sub']), "$fixtures/project");
	Assert::same([], $runner->findFiles(['src']));
	$result = $runner->processFile('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$runner = (new RunnerFactory)->createRunner(new Config(rules: [ReportContext::class => true], indent: 2, eol: 'LF'), "$fixtures/project");
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $runner->processFile('x.php', "<?php\r\n\$a;\r\n")->violations));
});


test('the command line lies over the configuration', function () use ($fixtures) {
	$runner = (new RunnerFactory)->createRunner(
		new Config(rules: [ReportContext::class => true]),
		"$fixtures/project",
		commandLine: new Profile(rules: [ReportContext::class => false, ReportVariable::class => true]),
		cache: false,
	);
	Assert::same(['test/b'], array_map(fn($v) => $v->ruleName, $runner->processFile('x.php', "<?php\n\$a;\n")->violations));
});


test('an extension makes its rules known by name, and brings the paths it leaves out and the files it skips', function () use ($fixtures) {
	Assert::exception(
		fn() => (new RunnerFactory)->createRunner(new Config(rules: ['test/a' => true]), "$fixtures/project"),
		ConfigurationException::class,
		"Unknown rule 'test/a'.",
	);

	$root = __DIR__ . '/../../temp/runner-factory-extensions';
	@mkdir("$root/sub", recursive: true); // @ - may exist
	file_put_contents("$root/checked.php", "<?php\n\$a;\n");
	file_put_contents("$root/generated.php", "<?php // @generated\n\$a;\n");
	file_put_contents("$root/skipped.php", "<?php // @skip\n\$a;\n");
	file_put_contents("$root/sub/excluded.php", "<?php\n\$a;\n");
	$runner = (new RunnerFactory)->createRunner(
		new Config(
			extensions: [ProjectExtension::class],
			rules: ['test/a' => true],
			skipWhen: fn(string $content) => str_contains($content, '@skip'),
		),
		$root,
		cache: false,
	);
	Assert::same(['test/a'], array_map(fn($rule) => RuleInfo::of($rule)->name, $runner->getProcessor()->getRules()));

	// the paths of every layer add up, and a file any layer skips is skipped
	$files = $runner->findFiles(['.']);
	Assert::same(['checked.php', 'generated.php', 'skipped.php'], $files);
	Assert::same(['checked.php'], array_map(fn($result) => $result->path, $runner->run($files, false, new NullReporter)->files));
});


test('an extension brings only what a package can, and a class that is none of the three says so', function () use ($fixtures) {
	$create = fn(Config $config) => (new RunnerFactory)->createRunner($config, "$fixtures/project", cache: false);
	Assert::exception(
		fn() => $create(new Config(extensions: [DecidingExtension::class])),
		ConfigurationException::class,
		'Extension DecidingExtension sets presets, which is for the project to decide; an extension sets extensions, analyses, excludePaths, skipWhen.',
	);
	Assert::exception(
		fn() => $create(new Config(extensions: ['DressCode\Missing'])),
		ConfigurationException::class,
		'Extension class DressCode\Missing does not exist.',
	);
	Assert::exception(
		fn() => $create(new Config(extensions: [stdClass::class])),
		ConfigurationException::class,
		'Extension stdClass is not an extension, a rule or a preset.',
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
		"Unknown rule 'test/nope'. (in the override for sub)",
	);

	// so is an option no rule takes, which would otherwise wait for a file of the override
	$invalid = new Config(overrides: [new Override(['sub'], rules: ['string-quotes' => ['quote' => 'single']])]);
	Assert::exception(
		fn() => (new RunnerFactory)->createRunner($invalid, "$fixtures/project"),
		ConfigurationException::class,
		"Invalid options of rule dresscode/string-quotes set by the override for sub: Unexpected item 'quote', did you mean 'quotes'?",
	);
});


test('an override brings its presets, its style, its name resolution and its warnings to its files', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$runner = $factory->createRunner(
		new Config(
			extensions: [DoubleQuotesPreset::class],
			rules: [ReportContext::class => true],
			nameResolution: 'certain',
			overrides: [new Override(['sub'], presets: ['test/double'], indent: 2, nameResolution: 'uncertain', warnings: [ReportContext::class])],
		),
		"$fixtures/project",
		cache: false,
	);
	$describe = fn(string $path) => array_map(
		fn($violation) => "$violation->ruleName {$violation->severity->name} $violation->message",
		$runner->processFile($path, "<?php\n\$a = 'text';\n")->violations,
	);
	Assert::same(['test/a Error 8.1 "\t""\n"'], $describe('src/x.php'));
	$sub = $describe('src/sub/x.php');
	Assert::count(2, $sub);
	Assert::same('test/a Warning 8.1 "  ""\n"', $sub[0]);
	Assert::match('dresscode/string-quotes Error %a%', $sub[1]);

	$guard = 'dresscode/no-unlisted-namespaced-declaration';
	$sub = $factory->resolveConfigFor($runner->findOverridesFor('src/sub/x.php'));
	Assert::same(['certain', true], [$factory->getResolvedConfig()->nameResolution, $factory->getResolvedConfig()->getRule($guard)?->isActive()]);
	Assert::same(['uncertain', false], [$sub->nameResolution, $sub->getRule($guard)?->isActive()]);
});


test('a runner keeps the configuration it was built from, whatever the factory builds after it', function () use ($fixtures) {
	$factory = new RunnerFactory;
	$runner = $factory->createRunner(
		new Config(rules: [ReportContext::class => true], overrides: [new Override(['sub'], rules: [ReportContext::class => false])]),
		"$fixtures/project",
		cache: false,
	);
	$factory->createRunner(new Config(rules: [ReportVariable::class => true]), "$fixtures/project", cache: false);

	// both processors are built lazily, the base one and the one of the override, and both after the second runner
	Assert::same(['test/a'], array_map(fn($v) => $v->ruleName, $runner->processFile('src/x.php', "<?php\n\$a;\n")->violations));
	Assert::same([], $runner->processFile('src/sub/x.php', "<?php\n\$a;\n")->violations);
});


test('a rule built by a closure is cached only under the text of the file the configuration came from', function () {
	$root = __DIR__ . '/../../temp/runner-factory';
	@mkdir($root, recursive: true); // @ - may exist
	Tester\Helpers::purge($root);
	file_put_contents("$root/x.php", "<?php\n\$x;\n");
	file_put_contents("$root/quiet.php", '<?php // quiet');
	file_put_contents("$root/loud.php", '<?php // loud');
	$quiet = fn() => new Config(rules: [ReportVariable::class => fn() => new ReportVariable(report: false)], cacheDir: "$root/cache");
	$loud = fn() => new Config(rules: [ReportVariable::class => fn() => new ReportVariable], cacheDir: "$root/cache");
	$run = fn(Config $config, ?string $file = null) => (new RunnerFactory)
		->createRunner($config, $root, configFile: $file)
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


test('the options of a rule built by a closure are part of the identity, whatever the file of the configuration says', function () {
	$root = __DIR__ . '/../../temp/runner-factory-options';
	@mkdir($root, recursive: true); // @ - may exist
	file_put_contents("$root/x.php", "<?php \$x = 'text';\n");
	file_put_contents("$root/config.php", '<?php // the same text for both runs');
	$config = new Config(
		extensions: [DoubleQuotesPreset::class],
		rules: [StringQuotesRule::class => fn() => new StringQuotesRule],
		cacheDir: "$root/cache",
	);
	$run = fn(?Profile $commandLine) => (new RunnerFactory)
		->createRunner($config, $root, $commandLine, configFile: "$root/config.php")
		->run(['x.php'], false, new NullReporter);

	// a preset from the command line changes the options, not the text of the file
	foreach ([[false, true], [true, false]] as $order) {
		Tester\Helpers::purge("$root/cache");
		foreach ($order as $double) {
			$result = $run($double ? new Profile(presets: ['test/double']) : null);
			Assert::same($double ? 1 : 0, $result->countViolations());
			Assert::false($result->files[0]->cached);
		}
	}
});


test('a rule of the project itself is part of the identity by the time its file changed', function () {
	$root = __DIR__ . '/../../temp/runner-factory-sources';
	@mkdir($root, recursive: true); // @ - may exist
	$file = "$root/TouchedRule.php";
	if (!class_exists('TouchedRule', autoload: false)) {
		file_put_contents($file, "<?php\n#[DressCode\\RuleInfo('test/touched', DressCode\\Stage::Structure)]\nfinal class TouchedRule extends DressCode\\NodeRule\n{\n\tpublic function getVisitedTypes(): array\n\t{\n\t\treturn [];\n\t}\n}\n");
		require $file;
	}

	file_put_contents("$root/x.php", "<?php\n");
	Tester\Helpers::purge("$root/cache");
	$cached = fn() => (new RunnerFactory)
		->createRunner(new Config(rules: ['TouchedRule' => true], cacheDir: "$root/cache"), $root)
		->run(['x.php'], false, new NullReporter)
		->files[0]->cached;

	Assert::false($cached());
	Assert::true($cached());
	touch($file, (int) filemtime($file) + 10);
	clearstatcache();
	Assert::false($cached());
	Assert::true($cached());
});


test('a package the project upgrades is part of the identity, however the tool itself was installed', function () {
	$root = __DIR__ . '/../../temp/runner-factory-packages';
	@mkdir("$root/vendor/composer", recursive: true); // @ - may exist
	Tester\Helpers::purge("$root/cache");
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
	$cached = fn() => (new RunnerFactory)
		->createRunner(new Config(rules: ['no-bom' => true], cacheDir: "$root/cache"), $root)
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


test('the name of the baseline is judged even before the file exists', function () use ($fixtures) {
	Assert::null(RunnerFactory::loadBaseline(new Config, $fixtures));
	Assert::null(RunnerFactory::loadBaseline(new Config(baseline: 'baseline.neon'), $fixtures)); // no file yet
	Assert::exception(
		fn() => RunnerFactory::loadBaseline(new Config(baseline: 'baseline.txt'), $fixtures),
		ConfigurationException::class,
		'The baseline file %a%baseline.txt must be a .neon or a .php file.',
	);
});
