<?php declare(strict_types=1);

use DressCode\Analyses;
use DressCode\Config;
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


#[RuleInfo('test/thrower', Stage::Cleanup)]
final class EngineThrower extends NodeRule
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
		// any extension but .phpt: the tree lies under tests/, where the runner collects every .phpt
		'src/c.phtml' => "<?php\n\$a;\n",
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
 * @param list<string> $fileExtensions
 * @param ?Closure(string, string): bool $skipWhen
 */
function engine(
	string $root,
	array $excludePaths = ['vendor', 'fixtures*'],
	array $fileExtensions = ['php'],
	?Closure $skipWhen = null,
	bool $thrower = false,
): Runner
{
	$processor = new FileProcessor($thrower ? [new EngineRename, new EngineThrower] : [new EngineRename], new Analyses\Registry, fn(string $name) => [$name], Config::DefaultPhpVersion);
	return new Runner($processor, $root, $excludePaths, $fileExtensions, $skipWhen);
}


test('a dot path is the root itself and never matches the dot-prefixed exclusion', function () use ($root) {
	$runner = engine($root, excludePaths: ['vendor', 'fixtures*', '.*']);
	Assert::same('', $runner->relativize('.'));
	Assert::same('src/a.php', $runner->relativize('./src/./a.php'));
	Assert::contains('src/a.php', $runner->findFiles(['.']));
	Assert::contains('src/a.php', $runner->findFiles(['./src']));
});


test('files are found under the paths, sorted, relative, with slashes, without the excluded ones', function () use ($root) {
	$runner = engine($root);
	Assert::same(
		['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'],
		$runner->findFiles([str_replace('/', '\\', $root) . '\src']),
	);
	Assert::same(['src/a.php', 'src/b.php', 'src/broken.php', 'src/skipped.php', 'src/sub/d.php'], $runner->findFiles(['src']));
	Assert::same(['src/sub/d.php', 'vendor/f.php'], $runner->findFiles(['./src/sub', 'vendor/f.php']));
	Assert::same(['src/sub/d.php'], $runner->findFiles(['./src/sub', 'vendor/f.php', 'src/fixtures/e.php'], skipExcluded: true));
	Assert::same(['src/c.phtml', 'src/fixtures/e.php'], engine($root, excludePaths: [], fileExtensions: ['php', 'phtml'])->findFiles(['src/c.phtml', 'src/fixtures']));
	Assert::exception(fn() => $runner->findFiles(['missing']), RuntimeException::class, 'Path missing does not exist.');
	$outside = str_replace('\\', '/', (string) realpath(__DIR__ . '/Config/fixtures/project/src'));
	Assert::same(["$outside/sub/file.php"], engine($root, excludePaths: [])->findFiles([$outside]));
	Assert::same(["$outside/sub/file.php"], $runner->findFiles(["$outside/sub/file.php"], skipExcluded: true)); // fixtures* matches nothing outside the root
});


test('check reports and writes nothing', function () use ($root) {
	$reporter = new RecordingReporter;
	$runner = engine($root, skipWhen: fn(string $content) => str_contains($content, '// skip me'));
	$result = $runner->run($runner->findFiles(['src']), fix: false, reporter: $reporter);
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
	$runner = engine($root);
	$result = $runner->run(['src/a.php', 'src/b.php'], fix: true, reporter: $reporter);
	Assert::same(['start 2 true', 'file src/a.php true true', 'file src/b.php false false', 'finish 1'], $reporter->events);
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/src/a.php"));
	Assert::same("<?php\n\$x;\n", file_get_contents("$root/src/b.php"));
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


test('processFile processes a text for the given path and writes nothing', function () use ($root) {
	$runner = engine($root);
	Assert::true($runner->processFile("$root/src/x.php", "<?php\n\$a;\n")->isChanged());
	Assert::same('src/x.php', $runner->processFile("$root/src/x.php", '<?php')->path);
	Assert::true($runner->hasExtension('src/x.PHP'));
	Assert::false($runner->hasExtension('src/x.phpt'));
});


test('the baseline silences a violation before the rule fixes it, and the run counts it', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$a;\n");

	$processor = fn(?DressCode\Engine\Baseline $baseline) => new FileProcessor(
		[new EngineRename],
		new Analyses\Registry,
		fn(string $name) => [$name],
		Config::DefaultPhpVersion,
		baseline: $baseline,
	);
	$run = new Runner($processor(null), $root)->run(['src/a.php'], false, new RecordingReporter);
	$baseline = DressCode\Engine\Baseline::fromResults($run->files);
	Assert::same(1, $baseline->count());

	// what the baseline knows is neither reported nor fixed, and fix leaves the file alone
	$runner = new Runner($processor($baseline), $root, baseline: $baseline);
	$run = $runner->run(['src/a.php', 'src/b.php'], true, new RecordingReporter);
	Assert::same(1, $run->countViolations()); // the one of src/b.php, which the baseline does not know
	Assert::same(1, $run->baselined);
	Assert::same(0, $run->getExitCode());
	Assert::same("<?php\n\$a;\n", (string) file_get_contents("$root/src/a.php"));
	Assert::same("<?php\n\$b;\n", (string) file_get_contents("$root/src/b.php")); // the same violation of another file is not known
	Assert::same([], $run->files[0]->violations);
	Assert::true($run->files[1]->violations[0]->fixable);
	Assert::same([], $run->warnings);

	// check reports what fix changed and nothing else
	file_put_contents("$root/src/b.php", "<?php\n\$a;\n");
	$fingerprints = fn(RunResult $run) => array_map(
		fn(FileResult $file) => array_map(fn($violation) => $violation->fingerprint, $file->violations),
		$run->files,
	);
	$check = new Runner($processor($baseline), $root, baseline: $baseline)->run(['src/a.php', 'src/b.php'], false, new RecordingReporter);
	$fix = new Runner($processor($baseline), $root, baseline: $baseline)->run(['src/a.php', 'src/b.php'], true, new RecordingReporter);
	Assert::same($fingerprints($check), $fingerprints($fix));
	Assert::same([false, true], array_map(fn(FileResult $file) => $file->written, $fix->files));
});


test('clean contents are remembered and skipped next time, a fixed file too', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	$file = "$root/cache.json";
	@unlink($file); // @ - may not exist
	$runner = fn() => new Runner(
		new FileProcessor([new EngineRename], new Analyses\Registry, fn(string $name) => [$name], Config::DefaultPhpVersion),
		$root,
		cache: DressCode\Engine\ResultCache::load($file, 'cfg'),
	);
	$cached = fn(RunResult $run) => array_map(fn(FileResult $r) => $r->cached, $run->files);

	$run = $runner()->run(['src/a.php', 'src/b.php'], false, new RecordingReporter);
	Assert::same([false, false], $cached($run));
	$run = $runner()->run(['src/a.php', 'src/b.php'], false, new RecordingReporter);
	Assert::same([false, true], $cached($run));
	Assert::same(1, $run->countViolations());

	$run = $runner()->run(['src/a.php'], true, new RecordingReporter);
	Assert::true($run->files[0]->written);
	$run = $runner()->run(['src/a.php', 'src/b.php'], false, new RecordingReporter);
	Assert::same([true, true], $cached($run));
	Assert::same(0, $run->countViolations());
	Assert::same(0, DressCode\Engine\ResultCache::load($file, 'other')->count());
});
