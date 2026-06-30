<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\PhpVersionSource;
use DressCode\Config\RunnerFactory;
use DressCode\ConfigurationException;
use DressCode\Extension;
use DressCode\NodeRule;
use DressCode\Override;
use DressCode\Profile;
use DressCode\Reporters\NullReporter;
use DressCode\RuleContext;
use DressCode\RuleInfo;
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
	$runner = $factory->createRunner(new Config(rules: [ReportContext::class => true]), $root);
	Assert::same(['8.0', PhpVersionSource::Composer], $factory->getPhpVersion());
	Assert::same([$warning], $factory->getWarnings());
	Assert::match('8.0 %a%', $runner->processFile('x.php', "<?php\n\$a;\n")->violations[0]->message);

	// every engine the factory builds starts with warnings of its own
	$factory->createRunner(new Config(php: '7.4'), $root);
	Assert::same(['8.0', PhpVersionSource::Configuration], $factory->getPhpVersion());
	Assert::same([$warning], $factory->getWarnings());
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
	);
	Assert::same(['test/a'], array_map(fn($rule) => RuleInfo::of($rule)->name, $runner->getProcessor()->getRules()));

	// the paths of every layer add up, and a file any layer skips is skipped
	$files = $runner->findFiles(['.']);
	Assert::same(['checked.php', 'generated.php', 'skipped.php'], $files);
	Assert::same(['checked.php'], array_map(fn($result) => $result->path, $runner->run($files, false, new NullReporter)->files));
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
