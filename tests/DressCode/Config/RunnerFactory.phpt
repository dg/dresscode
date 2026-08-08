<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\RunnerFactory;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;
use Tester\Assert;


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


test('the PHP version comes from the configuration, composer.json or the runtime', function () use ($fixtures) {
	$factory = new RunnerFactory;
	Assert::same('8.1', $factory->resolvePhpVersion(Config::create(), "$fixtures/project"));
	Assert::same('8.4', $factory->resolvePhpVersion(Config::create()->phpVersion('8.4'), "$fixtures/project"));
	Assert::same(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $factory->resolvePhpVersion(Config::create(), $fixtures));
	Assert::null(RunnerFactory::detectPhpVersion("$fixtures/none.json"));
});


test('the engine is built from the configuration', function () use ($fixtures) {
	$config = Config::create()->enable(ReportContext::class)->style(indent: 2)->excludePaths(['sub']);
	$runner = (new RunnerFactory)->createRunner($config, "$fixtures/project");
	Assert::same([], $runner->findFiles(['src']));
	$result = $runner->processFile('x.php', "<?php\r\n\$a;\r\n");
	Assert::same(['8.1 "  ""\r\n"'], array_map(fn($v) => $v->message, $result->violations));

	$runner = (new RunnerFactory)->createRunner($config->style(eol: 'lf'), "$fixtures/project");
	Assert::same(['8.1 "  ""\n"'], array_map(fn($v) => $v->message, $runner->processFile('x.php', "<?php\r\n\$a;\r\n")->violations));
});
