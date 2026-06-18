<?php declare(strict_types=1);

use DressCode\{Analyses, Config, FileResult, NodeRule, Reporter, RuleContext, RuleInfo, Runner, RunResult, Stage};
use DressCode\Engine\FileProcessor;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\{Assert, Helpers};

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


#[RuleInfo('test/path', Stage::Structure)]
final class EnginePath extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (str_ends_with($context->getPath(), 'b.php')) {
			$context->report($node, 'A variable in b.php');
		}
	}
}


#[RuleInfo('test/unfixable', Stage::Structure)]
final class EngineUnfixable extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [PhpSyntax\Nodes\FileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof PhpSyntax\Nodes\FileNode) {
			return;
		}

		$context->report($node, 'A problem no fix removes');
		foreach ($node->find(VariableNode::class) as $variable) {
			if (
				$variable->name instanceof Token
				&& $variable->name->text === '$a'
				&& $context->report($variable, 'Rename $a')
			) {
				$variable->name->setText('$b');
			}
		}
	}
}


/** Renames $a and meanwhile saves the file with another text, as an editor would. */
#[RuleInfo('test/saver', Stage::Structure)]
final class EngineSaver extends NodeRule
{
	public function __construct(
		private readonly string $file,
	) {
	}


	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof VariableNode
			&& $node->name instanceof Token
			&& $node->name->text === '$a'
			&& $context->report($node, 'Rename $a')
		) {
			$node->name->setText('$b');
			file_put_contents($this->file, "<?php\n\$saved;\n");
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
	Assert::same(1, $result->countSyntaxErrors());
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


test('fix writes nothing into a file saved by someone else while it was being fixed', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	$reporter = new RecordingReporter;
	$processor = new FileProcessor([new EngineSaver("$root/src/a.php")], new Analyses\Registry, fn(string $name) => [$name], Config::DefaultPhpVersion);

	$result = new Runner($processor, $root)->run(['src/a.php'], fix: true, reporter: $reporter);
	Assert::same([
		'start 1 true',
		'file src/a.php false false failure: The file changed while it was being fixed, so it was not written; run fix again.',
		'finish 0',
	], $reporter->events);
	Assert::same("<?php\n\$saved;\n", file_get_contents("$root/src/a.php"));
	Assert::same(2, $result->getExitCode());
});


test('a reporter sees the texts of a file, what the run keeps of it does not', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	$reporter = new class implements Reporter {
		/** @var list<array{string, ?string}> */
		public array $texts = [];


		public function start(int $fileCount, bool $fix): void
		{
		}


		public function reportFile(FileResult $result): void
		{
			$this->texts[] = [$result->code, $result->output];
		}


		public function finish(RunResult $result): void
		{
		}
	};

	$result = engine($root)->run(['src/a.php', 'src/b.php'], fix: false, reporter: $reporter);
	Assert::same([["<?php\n\$a;\n", "<?php\n\$b;\n"], ["<?php\n\$x;\n", "<?php\n\$x;\n"]], $reporter->texts);
	Assert::same([['', ''], ['', '']], array_map(fn(FileResult $file) => [$file->code, $file->output], $result->files));
	Assert::same([true, false], array_map(fn(FileResult $file) => $file->isChanged(), $result->files));
	Assert::same(1, $result->countChangedFiles());
});


test('processFile processes a text for the given path and writes nothing', function () use ($root) {
	$runner = engine($root);
	Assert::true($runner->processFile("$root/src/x.php", "<?php\n\$a;\n")->isChanged());
	Assert::same('src/x.php', $runner->processFile("$root/src/x.php", '<?php')->path);
});
