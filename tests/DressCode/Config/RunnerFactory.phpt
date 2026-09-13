<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\PhpVersionSource;
use DressCode\Config\RunnerFactory;
use DressCode\ConfigurationException;
use DressCode\NodeRule;
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


test('the PHP version comes from the configuration, composer.json or the default', function () use ($fixtures) {
	$factory = new RunnerFactory;
	Assert::same(
		['8.1', PhpVersionSource::Composer],
		[($v = $factory->resolvePhpVersion(Config::create(), "$fixtures/project"))[0], $v[1]],
	);
	Assert::same(
		['8.4', PhpVersionSource::Configuration],
		[($v = $factory->resolvePhpVersion(Config::create()->php('8.4'), "$fixtures/project"))[0], $v[1]],
	);
	// a directory without a composer.json of its own is answered by the nearest one above it
	Assert::same(
		['8.1', PhpVersionSource::Composer],
		[($v = $factory->resolvePhpVersion(Config::create(), "$fixtures/project/src"))[0], $v[1]],
	);
	Assert::same( // above the fixtures there is the composer.json of DressCode itself
		PhpVersionSource::Composer,
		$factory->resolvePhpVersion(Config::create(), $fixtures)[1],
	);
	Assert::same(
		[Config::DefaultPhpVersion, PhpVersionSource::Default],
		[($v = $factory->resolvePhpVersion(Config::create(), sys_get_temp_dir()))[0], $v[1]],
	);
});


test('the lowest version the constraint of require.php allows', function () use ($fixtures) {
	$detect = fn(string $json) => RunnerFactory::detectPhpVersion(FileMock::create($json, 'json'));
	Assert::same('8.2', $detect('{"require": {"php": "8.2 - 8.5"}}'));
	Assert::same('8.1', $detect('{"require": {"php": ">=8.1 <8.6"}}'));
	Assert::same('7.4', $detect('{"require": {"php": "^7.4 || ^8.0"}}'));
	Assert::same('8.0', $detect('{"require": {"php": "^8"}}'));
	Assert::null($detect('{"require": {"php": "*"}}'));
	Assert::null($detect('{"require": {}}'));
	Assert::null($detect('not json'));
	Assert::null(RunnerFactory::detectPhpVersion("$fixtures/none.json"));
	Assert::null(RunnerFactory::detectPhpVersion(null));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$config = Config::create()->enable(ReportContext::class)->indent(2)->excludePaths(['sub']);
	$runner = (new RunnerFactory)->createRunner($config, "$fixtures/project");
	Assert::same([], $runner->findFiles(['src']));
	$result = $runner->processFile('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$runner = (new RunnerFactory)->createRunner($config->eol('LF'), "$fixtures/project");
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $runner->processFile('x.php', "<?php\r\n\$a;\r\n")->violations));
});


test('an extension makes its rules known by name and sets up the run', function () use ($fixtures) {
	$config = Config::create()->enable('test/a');
	$factory = new RunnerFactory;
	Assert::exception(
		fn() => $factory->createRunner($config, "$fixtures/project"),
		ConfigurationException::class,
		"Unknown rule 'test/a'.",
	);

	$runner = $factory->createRunner(
		$config->extension(fn(Config $config) => $config->registerRules([ReportContext::class])->indent(2)),
		"$fixtures/project",
	);
	Assert::same(['test/a'], array_map(fn($rule) => RuleInfo::of($rule)->name, $runner->getProcessor()->getRules()));
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $runner->processFile('x.php', "<?php\n\$a;\n")->violations));
});


test('a block turns a rule off under its class as under its name, an unknown one is an error before any file', function () use ($fixtures) {
	$byClass = Config::create()->enable(ReportContext::class)->for(['sub'], [ReportContext::class => false]);
	$runner = (new RunnerFactory)->createRunner($byClass, "$fixtures/project");
	Assert::same([], $runner->processFile('src/sub/x.php', "<?php\n\$a;\n")->violations);
	Assert::count(1, $runner->processFile('src/x.php', "<?php\n\$a;\n")->violations);

	$unknown = Config::create()->enable(ReportContext::class)->for(['sub'], ['test/nope' => false]);
	Assert::exception(
		fn() => (new RunnerFactory)->createRunner($unknown, "$fixtures/project"),
		ConfigurationException::class,
		"Unknown rule 'test/nope'.",
	);
});


test('the name of the baseline is judged even before the file exists', function () use ($fixtures) {
	Assert::null(RunnerFactory::loadBaseline(Config::create(), $fixtures));
	Assert::null(RunnerFactory::loadBaseline(Config::create()->baseline('baseline.neon'), $fixtures)); // no file yet
	Assert::exception(
		fn() => RunnerFactory::loadBaseline(Config::create()->baseline('baseline.txt'), $fixtures),
		ConfigurationException::class,
		'The baseline file %a%baseline.txt must be a .neon or a .php file.',
	);
});
