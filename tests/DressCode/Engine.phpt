<?php declare(strict_types=1);

use DressCode\AnalysisRegistry;
use DressCode\Engine;
use DressCode\Engine\FileProcessor;
use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;
use Tester\Assert;
use Tester\Helpers;

require __DIR__ . '/../bootstrap.php';


#[RuleInfo('test/rename', Stage::Structure)]
final class EngineRename extends Rule
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
 * @param list<string> $skip
 * @param array<string, list<string>> $ruleSkip
 * @param list<string> $extensions
 * @param ?Closure(string, string): bool $skipWhen
 */
function engine(
	string $root,
	array $skip = ['vendor', 'fixtures*'],
	array $ruleSkip = [],
	array $extensions = ['php'],
	?Closure $skipWhen = null,
): Engine
{
	$processor = new FileProcessor([new EngineRename], new AnalysisRegistry, fn(string $name) => $name);
	return new Engine($processor, $root, $skip, $ruleSkip, $extensions, $skipWhen);
}


test('a dot path is the root itself and never matches the dot-prefixed skip', function () use ($root) {
	$engine = engine($root, skip: ['vendor', 'fixtures*', '.*']);
	Assert::same('', $engine->relativize('.'));
	Assert::same('src/a.php', $engine->relativize('./src/./a.php'));
	Assert::contains('src/a.php', $engine->findFiles(['.']));
	Assert::contains('src/a.php', $engine->findFiles(['./src']));
});


test('files are found under the paths, sorted, relative, with slashes, without the skipped ones', function () use ($root) {
	$engine = engine($root);
	Assert::same(
		['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'],
		$engine->findFiles([str_replace('/', '\\', $root) . '\src']),
	);
	Assert::same(['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'], $engine->findFiles(['src']));
	Assert::same(['src/sub/d.php', 'vendor/f.php'], $engine->findFiles(['./src/sub', 'vendor/f.php']));
	Assert::same(['src/c.phpt', 'src/fixtures/e.php'], engine($root, skip: [], extensions: ['php', 'phpt'])->findFiles(['src/c.phpt', 'src/fixtures']));
	Assert::exception(fn() => $engine->findFiles(['missing']), RuntimeException::class, 'Path missing does not exist.');
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
	$result = engine($root, ruleSkip: ['test/rename' => ['src/sub']])->run(['src/a.php', 'src/sub'], fix: true, reporter: $reporter);
	Assert::same(['start 2 true', 'file src/a.php true true', 'file src/sub/d.php false false', 'finish 1'], $reporter->events);
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/src/a.php"));
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/src/sub/d.php"));
	Assert::same(0, $result->getExitCode());
});


test('processFile applies the rule skips to the given path and writes nothing', function () use ($root) {
	$engine = engine($root, ruleSkip: ['test/rename' => ['src/sub']]);
	Assert::true($engine->processFile("$root/src/x.php", "<?php\n\$a;\n")->isChanged());
	Assert::false($engine->processFile('src/sub/x.php', "<?php\n\$a;\n")->isChanged());
	Assert::same('src/x.php', $engine->processFile("$root/src/x.php", '<?php')->path);
	Assert::true($engine->hasExtension('src/x.PHP'));
	Assert::false($engine->hasExtension('src/x.phpt'));
});
