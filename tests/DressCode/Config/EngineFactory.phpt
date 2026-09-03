<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\EngineFactory;
use DressCode\Config\PhpVersionSource;
use DressCode\ConfigurationException;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\PhpVersion;
use PhpSyntax\Token;
use Tester\Assert;
use Tester\FileMock;

require __DIR__ . '/../../bootstrap.php';

$fixtures = str_replace('\\', '/', __DIR__) . '/fixtures';


#[RuleInfo('test/a', Stage::Structure)]
final class ReportContext extends Rule
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
	$factory = new EngineFactory;
	Assert::same(
		['8.1', PhpVersionSource::Composer],
		[(string) ($v = $factory->resolvePhpVersion(Config::create(), "$fixtures/project"))[0], $v[1]],
	);
	Assert::same(
		['8.4', PhpVersionSource::Configuration],
		[(string) ($v = $factory->resolvePhpVersion(Config::create()->phpVersion('8.4'), "$fixtures/project"))[0], $v[1]],
	);
	// a directory without a composer.json of its own is answered by the nearest one above it
	Assert::same(
		['8.1', PhpVersionSource::Composer],
		[(string) ($v = $factory->resolvePhpVersion(Config::create(), "$fixtures/project/src"))[0], $v[1]],
	);
	Assert::same( // above the fixtures there is the composer.json of DressCode itself
		PhpVersionSource::Composer,
		$factory->resolvePhpVersion(Config::create(), $fixtures)[1],
	);
	Assert::same(
		[PhpVersion::Lowest, PhpVersionSource::Default],
		[(string) ($v = $factory->resolvePhpVersion(Config::create(), sys_get_temp_dir()))[0], $v[1]],
	);
});


test('the lowest version the constraint of require.php allows', function () use ($fixtures) {
	$detect = fn(string $json) => EngineFactory::detectPhpVersion(FileMock::create($json, 'json'));
	Assert::same('8.2', (string) $detect('{"require": {"php": "8.2 - 8.5"}}'));
	Assert::same('8.1', (string) $detect('{"require": {"php": ">=8.1 <8.6"}}'));
	Assert::same('7.4', (string) $detect('{"require": {"php": "^7.4 || ^8.0"}}'));
	Assert::same('8.0', (string) $detect('{"require": {"php": "^8"}}'));
	Assert::null($detect('{"require": {"php": "*"}}'));
	Assert::null($detect('{"require": {}}'));
	Assert::null($detect('not json'));
	Assert::null(EngineFactory::detectPhpVersion("$fixtures/none.json"));
	Assert::null(EngineFactory::detectPhpVersion(null));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$config = Config::create()->enable(ReportContext::class)->style(indent: '  ')->excludePaths(['sub']);
	$engine = (new EngineFactory)->createEngine($config, "$fixtures/project");
	Assert::same([], $engine->findFiles(['src']));
	$result = $engine->processFile('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$engine = (new EngineFactory)->createEngine($config->style(eol: "\n"), "$fixtures/project");
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $engine->processFile('x.php', "<?php\r\n\$a;\r\n")->violations));
});


test('an extension makes its rules known by name and sets up the run', function () use ($fixtures) {
	$config = Config::create()->enable('test/a');
	$factory = new EngineFactory;
	Assert::exception(
		fn() => $factory->createEngine($config, "$fixtures/project"),
		ConfigurationException::class,
		"Unknown rule 'test/a'.",
	);

	$engine = $factory->createEngine(
		$config->extension(fn(Config $config) => $config->registerRules([ReportContext::class])->style(indent: '  ')),
		"$fixtures/project",
	);
	Assert::same(['test/a'], array_map(fn($rule) => RuleInfo::of($rule)->name, $engine->getProcessor()->getRules()));
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $engine->processFile('x.php', "<?php\n\$a;\n")->violations));
});


test('a rule is left out of paths under its class as under its name, an unknown one is an error', function () use ($fixtures) {
	$byClass = Config::create()->enable(ReportContext::class)->excludeRulePaths(ReportContext::class, ['sub']);
	$engine = (new EngineFactory)->createEngine($byClass, "$fixtures/project");
	Assert::same([], $engine->processFile('src/sub/x.php', "<?php\n\$a;\n")->violations);
	Assert::count(1, $engine->processFile('src/x.php', "<?php\n\$a;\n")->violations);

	$unknown = Config::create()->enable(ReportContext::class)->excludeRulePaths('test/nope', ['sub']);
	Assert::exception(
		fn() => (new EngineFactory)->createEngine($unknown, "$fixtures/project"),
		ConfigurationException::class,
		"Unknown rule 'test/nope'.",
	);
});
