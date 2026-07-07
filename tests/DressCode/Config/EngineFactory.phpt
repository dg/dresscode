<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\EngineFactory;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\PhpVersion;
use PhpSyntax\Token;
use Tester\Assert;

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


test('the PHP version comes from the configuration, composer.json or the runtime', function () use ($fixtures) {
	$factory = new EngineFactory;
	Assert::same('8.1', (string) $factory->resolvePhpVersion(Config::create(), "$fixtures/project"));
	Assert::same('8.4', (string) $factory->resolvePhpVersion(Config::create()->phpVersion('8.4'), "$fixtures/project"));
	Assert::same((string) PhpVersion::current(), (string) $factory->resolvePhpVersion(Config::create(), $fixtures));
	Assert::null(EngineFactory::detectPhpVersion("$fixtures/none.json"));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$config = Config::create()->enable(ReportContext::class)->style(indent: '  ')->skip(['sub']);
	$engine = (new EngineFactory)->createEngine($config, "$fixtures/project");
	Assert::same([], $engine->findFiles(['src']));
	$result = $engine->processFile('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$engine = (new EngineFactory)->createEngine($config->style(eol: "\n"), "$fixtures/project");
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $engine->processFile('x.php', "<?php\r\n\$a;\r\n")->violations));
});
