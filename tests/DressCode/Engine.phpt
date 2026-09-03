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
use PhpSyntax\PhpVersion;
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


#[RuleInfo('test/thrower', Stage::Cleanup)]
final class EngineThrower extends Rule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$x') {
			throw new RuntimeException('boom');
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
		$this->events[] = "file $result->path " . json_encode($result->isChanged()) . ' ' . json_encode($result->written)
			. ($result->failure === null ? '' : " failure: $result->failure");
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
 * @param list<string> $fileExtensions
 * @param ?Closure(string, string): bool $skipWhen
 */
function engine(
	string $root,
	array $excludePaths = ['vendor', 'fixtures*'],
	array $ruleExcludePaths = [],
	array $fileExtensions = ['php'],
	?Closure $skipWhen = null,
	bool $thrower = false,
): Engine
{
	$processor = new FileProcessor($thrower ? [new EngineRename, new EngineThrower] : [new EngineRename], new AnalysisRegistry, fn(string $name) => [$name], PhpVersion::lowest());
	return new Engine($processor, $root, $excludePaths, $ruleExcludePaths, $fileExtensions, $skipWhen);
}


test('a dot path is the root itself and never matches the dot-prefixed exclusion', function () use ($root) {
	$engine = engine($root, excludePaths: ['vendor', 'fixtures*', '.*']);
	Assert::same('', $engine->relativize('.'));
	Assert::same('src/a.php', $engine->relativize('./src/./a.php'));
	Assert::contains('src/a.php', $engine->findFiles(['.']));
	Assert::contains('src/a.php', $engine->findFiles(['./src']));
});


test('files are found under the paths, sorted, relative, with slashes, without the excluded ones', function () use ($root) {
	$engine = engine($root);
	Assert::same(
		['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'],
		$engine->findFiles([str_replace('/', '\\', $root) . '\src']),
	);
	Assert::same(['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'], $engine->findFiles(['src']));
	Assert::same(['src/sub/d.php', 'vendor/f.php'], $engine->findFiles(['./src/sub', 'vendor/f.php']));
	Assert::same(['src/c.phpt', 'src/fixtures/e.php'], engine($root, excludePaths: [], fileExtensions: ['php', 'phpt'])->findFiles(['src/c.phpt', 'src/fixtures']));
	Assert::exception(fn() => $engine->findFiles(['missing']), RuntimeException::class, 'Path missing does not exist.');
	$outside = str_replace('\\', '/', (string) realpath(__DIR__ . '/Config/fixtures/project/src'));
	Assert::same(["$outside/sub/file.php"], engine($root, excludePaths: [])->findFiles([$outside]));
});


test('check reports and writes nothing', function () use ($root) {
	$reporter = new RecordingReporter;
	$engine = engine($root, skipWhen: fn(string $content) => str_contains($content, '// skip me'));
	$result = $engine->run($engine->findFiles(['src']), fix: false, reporter: $reporter);
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
	$engine = engine($root, ruleExcludePaths: ['test/rename' => ['src/sub']]);
	$result = $engine->run($engine->findFiles(['src/a.php', 'src/sub']), fix: true, reporter: $reporter);
	Assert::same(['start 2 true', 'file src/a.php true true', 'file src/sub/d.php false false', 'finish 1'], $reporter->events);
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/src/a.php"));
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/src/sub/d.php"));
	Assert::same(0, $result->getExitCode());
});


test('a failing rule fails the file, the run goes on, nothing is written', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	$reporter = new RecordingReporter;
	$result = engine($root, thrower: true)->run(['src/a.php', 'src/b.php'], fix: true, reporter: $reporter);
	Assert::same([
		'start 2 true',
		'file src/a.php true true',
		'file src/b.php false false failure: Rule test/thrower failed in src/b.php: boom',
		'finish 1',
	], $reporter->events);
	Assert::same("<?php\n\$x;\n", file_get_contents("$root/src/b.php"));
	Assert::same(2, $result->getExitCode());
	Assert::same(1, $result->countFailures());
});


test('processFile applies the rule exclusions to the given path and writes nothing', function () use ($root) {
	$engine = engine($root, ruleExcludePaths: ['test/rename' => ['src/sub']]);
	Assert::true($engine->processFile("$root/src/x.php", "<?php\n\$a;\n")->isChanged());
	Assert::false($engine->processFile('src/sub/x.php', "<?php\n\$a;\n")->isChanged());
	Assert::same('src/x.php', $engine->processFile("$root/src/x.php", '<?php')->path);
	Assert::true($engine->hasExtension('src/x.PHP'));
	Assert::false($engine->hasExtension('src/x.phpt'));
});


test('clean contents are remembered and skipped next time, a fixed file too', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	$file = "$root/cache.json";
	@unlink($file); // @ - may not exist
	$engine = fn() => new Engine(
		new FileProcessor([new EngineRename], new AnalysisRegistry, fn(string $name) => [$name], PhpVersion::lowest()),
		$root,
		cache: DressCode\Engine\ResultCache::load($file, 'cfg'),
	);
	$cached = fn(RunResult $run) => array_map(fn(FileResult $r) => $r->cached, $run->files);

	$run = $engine()->run(['src/a.php', 'src/b.php'], false, new RecordingReporter);
	Assert::same([false, false], $cached($run));
	$run = $engine()->run(['src/a.php', 'src/b.php'], false, new RecordingReporter);
	Assert::same([false, true], $cached($run));
	Assert::same(1, $run->countViolations());

	$run = $engine()->run(['src/a.php'], true, new RecordingReporter);
	Assert::true($run->files[0]->written);
	$run = $engine()->run(['src/a.php', 'src/b.php'], false, new RecordingReporter);
	Assert::same([true, true], $cached($run));
	Assert::same(0, $run->countViolations());
	Assert::same(0, DressCode\Engine\ResultCache::load($file, 'other')->count());
});
