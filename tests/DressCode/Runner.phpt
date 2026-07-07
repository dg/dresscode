<?php declare(strict_types=1);

use DressCode\Analyses;
use DressCode\Engine\FileProcessor;
use DressCode\FileResult;
use DressCode\NodeRule;
use DressCode\Reporter;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Runner;
use DressCode\RunResult;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;
use Tester\Assert;
use Tester\Helpers;


require __DIR__ . '/../bootstrap.php';


#[RuleInfo('test/rename', Stage::Structure)]
final class EngineRename extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Rename $a')) {
				$node->name->setText('$b');
			}
		}
	}
}


final class RecordingReporter implements Reporter
{
	/** @var list<string> */
	public array $events = [];


	public function start(int $fileCount, bool $fix): void
	{
		$this->events[] = "start $fileCount " . json_encode($fix);
	}


	public function reportFile(FileResult $result): void
	{
		$this->events[] = "file $result->path " . json_encode($result->isChanged()) . ' ' . json_encode($result->written);
	}


	public function finish(RunResult $result): void
	{
		$this->events[] = 'finish ' . $result->countViolations();
	}
}


$root = __DIR__ . '/../temp/engine';
@mkdir($root, recursive: true); // @ - may exist
$root = (string) realpath($root);
Helpers::purge($root);
foreach (
	[
		'src/a.php' => "<?php\n\$a;\n",
		'src/b.php' => "<?php\n\$x;\n",
		'src/c.phpt' => "<?php\n\$a;\n",
		'src/sub/d.php' => "<?php\n\$a;\n",
		'src/fixtures/e.php' => "<?php\n\$a;\n",
		'vendor/f.php' => "<?php\n\$a;\n",
		'src/broken.php' => "<?php\n\$a = ;\n",
		'src/skipped.php' => "<?php\n// skip me\n\$a;\n",
	] as $path => $content
) {
	@mkdir(dirname("$root/$path"), recursive: true);
	file_put_contents("$root/$path", $content);
}


/**
 * @param list<string> $excludePaths
 * @param array<string, list<string>> $ruleExcludePaths
 * @param list<string> $extensions
 * @param ?Closure(string, string): bool $skipWhen
 */
function engine(
	string $root,
	array $excludePaths = ['vendor', 'fixtures*'],
	array $ruleExcludePaths = [],
	array $extensions = ['php'],
	?Closure $skipWhen = null,
): Runner
{
	$processor = new FileProcessor([new EngineRename], new Analyses\Registry, fn(string $name) => [$name], '8.0');
	return new Runner($processor, $root, $excludePaths, $ruleExcludePaths, $extensions, $skipWhen);
}


test('a dot path is the root itself and never matches the dot-prefixed skip', function () use ($root) {
	$runner = engine($root, excludePaths: ['vendor', 'fixtures*', '.*']);
	Assert::same('', $runner->relativize('.'));
	Assert::same('src/a.php', $runner->relativize('./src/./a.php'));
	Assert::contains('src/a.php', $runner->findFiles(['.']));
	Assert::contains('src/a.php', $runner->findFiles(['./src']));
});


test('files are found under the paths, sorted, relative, with slashes, without the skipped ones', function () use ($root) {
	$runner = engine($root);
	Assert::same(
		['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'],
		$runner->findFiles([str_replace('/', '\\', $root) . '\src']),
	);
	Assert::same(['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'], $runner->findFiles(['src']));
	Assert::same(['src/sub/d.php', 'vendor/f.php'], $runner->findFiles(['./src/sub', 'vendor/f.php']));
	Assert::same(['src/c.phpt', 'src/fixtures/e.php'], engine($root, excludePaths: [], extensions: ['php', 'phpt'])->findFiles(['src/c.phpt', 'src/fixtures']));
	Assert::exception(fn() => $runner->findFiles(['missing']), RuntimeException::class, 'Path missing does not exist.');
});


test('check reports and writes nothing', function () use ($root) {
	$reporter = new RecordingReporter;
	$result = engine($root, skipWhen: fn(string $content) => str_contains($content, '// skip me'))->run(['src'], fix: false, reporter: $reporter);
	Assert::same([
		'start 5 false',
		'file src/a.php true false',
		'file src/b.php false false',
		'file src/broken.php false false',
		'file src/sub/d.php true false',
		'finish 2',
	], $reporter->events);
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/src/a.php"));
	Assert::same(1, $result->getExitCode());
	Assert::same(1, $result->countErrors());
	Assert::same(2, $result->countChangedFiles());
});


test('fix writes the changed files', function () use ($root) {
	$reporter = new RecordingReporter;
	$result = engine($root, ruleExcludePaths: ['test/rename' => ['src/sub']])->run(['src/a.php', 'src/sub'], fix: true, reporter: $reporter);
	Assert::same(['start 2 true', 'file src/a.php true true', 'file src/sub/d.php false false', 'finish 1'], $reporter->events);
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/src/a.php"));
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/src/sub/d.php"));
	Assert::same(0, $result->getExitCode());
});


test('processFile applies the rule skips to the given path and writes nothing', function () use ($root) {
	$runner = engine($root, ruleExcludePaths: ['test/rename' => ['src/sub']]);
	Assert::true($runner->processFile("$root/src/x.php", "<?php\n\$a;\n")->isChanged());
	Assert::false($runner->processFile('src/sub/x.php', "<?php\n\$a;\n")->isChanged());
	Assert::same('src/x.php', $runner->processFile("$root/src/x.php", '<?php')->path);
	Assert::true($runner->hasExtension('src/x.PHP'));
	Assert::false($runner->hasExtension('src/x.phpt'));
});
