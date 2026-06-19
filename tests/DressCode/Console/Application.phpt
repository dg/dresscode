<?php declare(strict_types=1);

use DressCode\Console\Application;
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\{Assert, Helpers};

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/rename', Stage::Structure, description: 'Renames $a to $b')]
final class ConsoleRename extends NodeRule
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


#[RuleInfo('test/risky-rename', Stage::Structure, description: 'Renames $r to $s, which may change what the code does')]
final class ConsoleRiskyRename extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$r') {
			if ($context->report($node, 'Rename $r', risky: true)) {
				$node->name->setText('$s');
			}
		}
	}
}


#[RuleInfo('test/report', Stage::Formatting)]
final class ConsoleReport extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'Seen');
	}
}


$root = __DIR__ . '/../../temp/console';
@mkdir($root, recursive: true); // @ - may exist
$root = str_replace('\\', '/', (string) realpath($root));
Helpers::purge($root);
@mkdir("$root/src");
// the target is pinned, so that a rule of a newer PHP has something to be newer than
file_put_contents("$root/dresscode.php", "<?php\nreturn new DressCode\\Config(rules: [ConsoleRename::class => true], paths: ['src'], php: '8.3');\n");
file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
file_put_contents("$root/src/b.php", "<?php\n\$x;\n");


/**
 * @param  list<string>  $args
 * @return array{int, string, string}
 */
function runApp(string $root, array $args, string $stdin = '', bool $xdebug = false): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$in = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	fwrite($in, $stdin);
	rewind($in);
	$code = new Application($out, $err, $in, $root, xdebug: $xdebug)->run(['dresscode', ...$args]);
	rewind($out);
	rewind($err);
	// how long a run took is up to the machine, which a busy one makes long enough to be said
	$clean = fn($stream) => (string) preg_replace('~, \d+\.\d s$~m', '', (string) stream_get_contents($stream));
	return [$code, $clean($out), $clean($err)];
}


test('help and version', function () use ($root) {
	[$code, $out] = runApp($root, []);
	Assert::same(2, $code);
	Assert::match('DRESS%a%CODE %a%' . "\n\nUsage:%A%", $out);
	[$code, $out] = runApp($root, ['--help']);
	Assert::same(0, $code);
	Assert::match('DRESS%a%CODE %a%' . "\n\nUsage:%A%", $out);
	[$code, $out] = runApp($root, ['--version']);
	Assert::same(0, $code);
	Assert::match('DRESS%a%CODE ' . Application::Version . "\n", $out);
});


test('check with the configured paths', function () use ($root) {
	[$code, $out, $err] = runApp($root, ['check']);
	Assert::same(1, $code);
	Assert::same('', $err);
	Assert::match(<<<'XX'
		DRESS|CODE %a%
		Config     %a%dresscode.php
		Target     PHP %a%
		Checking   2 files in %a%src

		%a%a.php
		  error  2:1  Rename $a  test/rename

		FOUND  1 violation, a fix leaves none in 1 of 2 files

		XX, $out);
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/src/a.php"));
});


test('a run under Xdebug warns that it is slower, in the formats a person reads', function () use ($root) {
	Assert::same("Warning: Xdebug is loaded and makes the run many times slower.\n", runApp($root, ['check'], xdebug: true)[2]);
	Assert::same('', runApp($root, ['check', '--format', 'json'], xdebug: true)[2]);
});


test('check of a clean path with options from the command line', function () use ($root) {
	[$code, $out] = runApp($root, ['check', 'src/b.php', '--rule', 'test/rename=off']);
	Assert::same(0, $code);
	Assert::match("%A%OK  no violations in 1 file\n", $out);
	[$code, $out] = runApp($root, ['check', 'src/b.php', '--rule', ConsoleReport::class . '=on', '--format', 'json']);
	Assert::same(1, $code);
	Assert::match('%A%"rule": "test/report",%A%', $out);
});


test('a path outside the root is checked where it is', function () use ($root) {
	$outside = dirname($root) . '/console-outside';
	@mkdir($outside); // @ - may exist
	file_put_contents("$outside/c.php", "<?php\n\$x;\n");
	[$code, $out] = runApp($root, ['check', "$outside/c.php"]);
	Assert::same(0, $code);
	Assert::match("%A%Checking   %a%console-outside%a%c.php\n%A%", $out);
});


test('stdin: check reports, fix writes the result to stdout', function () use ($root) {
	[$code, $out] = runApp($root, ['check', '--stdin', 'src/x.php'], "<?php\n\$a;\n");
	Assert::same(1, $code);
	Assert::match("%a%x.php\n  error  2:1  Rename \$a  test/rename\n\nFOUND  1 violation, a fix leaves none in 1 file\n", $out);
	[$code, $out, $err] = runApp($root, ['fix', '--stdin', 'src/x.php'], "<?php\n\$a;\n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$b;\n", $out);
	Assert::match("%a%x.php  rewritten\n\nFIXED  1 violation found, none remaining in 1 file\n", $err);
});


test('errors go to stderr with exit code 2', function () use ($root) {
	[$code, $out, $err] = runApp($root, ['check', '--nope']);
	Assert::same(2, $code);
	Assert::same('', $out);
	Assert::match("Error: Unknown option --nope.\n\nUsage:%A%", $err);
	[$code, , $err] = runApp($root, ['check', '--rule', 'test/none=on']);
	Assert::same(2, $code);
	Assert::same("Error: Unknown rule 'test/none'.\n", $err);
	[$code, , $err] = runApp($root, ['check', '--format', 'xml']);
	Assert::same(2, $code);
	Assert::match("Error: Option --format: expects console, bare, github, json or checkstyle, 'xml' given.%A%", $err);
	[$code, , $err] = runApp($root, ['check', '--config', "$root/none.php"]);
	Assert::same(2, $code);
	Assert::match('Error: Configuration file %a% does not exist.%A%', $err);
	[$code, , $err] = runApp($root, ['wat']);
	Assert::same(2, $code);
	Assert::match("Error: Unknown command 'wat'.%A%", $err);
});


test('bare says what is left to the user and which files it rewrote', function () use ($root) {
	file_put_contents("$root/src/q.php", "<?php\n\$a;\n");
	try {
		[$code, $out] = runApp($root, ['check', '--format', 'bare', 'src/q.php']);
		Assert::same(1, $code);
		Assert::match("src%a%q.php\n  error  2:1  Rename \$a  test/rename\n", $out);
		[$code, $out] = runApp($root, ['check', '-f', 'bare', 'src/b.php']);
		Assert::same(0, $code);
		Assert::same('', $out); // a clean run says nothing at all
		[$code, $out] = runApp($root, ['fix', '-f', 'bare', 'src/q.php']);
		Assert::same(0, $code);
		Assert::match("src%a%q.php  rewritten\n", $out); // the hook has to know its file has changed
		[$code, $out] = runApp($root, ['fix', '-f', 'bare', 'src/q.php']);
		Assert::same(0, $code);
		Assert::same('', $out);
	} finally {
		unlink("$root/src/q.php");
	}
});


test('a named file the configuration excludes is checked unless the caller asks to skip it', function () use ($root) {
	@mkdir("$root/vendor");
	file_put_contents("$root/vendor/v.php", "<?php\n\$a;\n");
	try {
		[$code, $out] = runApp($root, ['check', '-f', 'bare', 'vendor/v.php']);
		Assert::same(1, $code);
		Assert::match("vendor%a%v.php\n  error  2:1  Rename \$a  test/rename\n", $out);
		[$code, $out] = runApp($root, ['fix', 'vendor/v.php', '--skip-excluded']);
		Assert::same(0, $code);
		Assert::match("%A%Nothing to fix: no file in %a%v.php\n", $out);
		Assert::same("<?php\n\$a;\n", file_get_contents("$root/vendor/v.php"));
	} finally {
		unlink("$root/vendor/v.php");
	}
});


test('a run inside GitHub Actions annotates without being told to', function () use ($root) {
	putenv('GITHUB_ACTIONS=true');
	putenv("GITHUB_WORKSPACE=$root");
	[$code, $out] = runApp($root, ['check']);
	Assert::same(1, $code);
	Assert::match("%A%::error file=src/a.php,line=2,col=1,title=test/rename::Rename \$a\n1 violation in 2 files\n", $out);
	[$code, $out] = runApp($root, ['check', '--format', 'console']);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  1 violation%A%', $out);
	[$code, $out] = runApp($root, ['check', '--stdin', 'src/x.php'], "<?php\n\$a;\n"); // an editor asked, not the workflow
	Assert::same(1, $code);
	Assert::match('%A%FOUND  1 violation%A%', $out);
	putenv('GITHUB_ACTIONS');
	putenv('GITHUB_WORKSPACE');
});


test('fix writes the files and reports what remains', function () use ($root) {
	[$code, $out] = runApp($root, ['fix', '--diff', '--rule', ConsoleReport::class . '=on']);
	Assert::same(1, $code);
	Assert::match(<<<'XX'
		%A%
		%a%a.php  rewritten
		  error  2:1  Seen  test/report
		--- src/a.php
		+++ src/a.php
		@@ -1,2 +1,2 @@
		 <?php
		-$a;
		+$b;

		%a%b.php
		  error  2:1  Seen  test/report

		FIXED  3 violations found, 2 remaining in 2 files

		XX, $out);
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/src/a.php"));
});


test('exit codes: violations, warnings, the warning threshold, a syntax error and a failing rule', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	$config = "<?php\nreturn new DressCode\\Config(rules: [ConsoleRename::class => true, ConsoleReport::class => true], paths: ['src']";
	$write = fn(string $tail) => file_put_contents("$root/exit.php", "$config$tail);\n");
	/** @param list<string> $args */
	$run = fn(array $args = []) => runApp($root, array_values(['check', '--config', "$root/exit.php", ...$args]))[0];

	$write('');
	Assert::same(1, $run()); // violations of both rules
	Assert::same(1, runApp($root, ['fix', '--config', "$root/exit.php"])[0]); // test/report fixes nothing
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	// the same violations as warnings: reported, counted, and the exit code stays clean
	$write(', warnings: [ConsoleRename::class, ConsoleReport::class]');
	[$code, $out] = runApp($root, ['check', '--config', "$root/exit.php"]);
	Assert::same(0, $code);
	Assert::match('%A%  warning  2:1  Rename $a  test/rename%A%FOUND  3 warnings, a fix leaves 2 in 2 files%A%', $out);
	Assert::same(0, $run(['--max-warnings', '3']));
	Assert::same(1, $run(['--max-warnings', '2']));

	// a rule left as an error decides the exit code whatever the threshold says
	$write(', warnings: [ConsoleReport::class]');
	Assert::same(1, $run(['--max-warnings', '100']));

	// a syntax error is 1 even when every rule only warns
	$write(', warnings: [ConsoleRename::class, ConsoleReport::class]');
	file_put_contents("$root/src/broken.php", "<?php\n\$a = ;\n");
	Assert::same(1, $run());
	unlink("$root/src/broken.php");
	Assert::same(0, $run());
});


test('a risky fix waits for the run to allow it, and is a violation until it is made', function () use ($root) {
	Helpers::purge("$root/src");
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$config = "$root/risky.php";
	$write = fn(string $tail) => file_put_contents($config, "<?php\nreturn new DressCode\\Config(rules: [ConsoleRiskyRename::class => true], paths: ['src']$tail);\n");

	// refused: reported, counted apart, not fixed, and the exit code says the code is not clean
	$write('');
	[$code, $out] = runApp($root, ['fix', '--config', $config]);
	Assert::same(1, $code);
	Assert::contains('1 of them risky (test/risky-rename), fixed once their rules are named in fixRisky or with --fix-risky', $out);
	Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));

	// the flag of the run allows it
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--fix-risky']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));
	Assert::notContains('of them risky', $out);

	// and so does the configuration, for the rules it names
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write(', fixRisky: [ConsoleRiskyRename::class]');
	Assert::same(0, runApp($root, ['fix', '--config', $config])[0]);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));

	// the JSON says of every violation whether it was risky and how many are waiting
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write('');
	[, $out] = runApp($root, ['check', '--config', $config, '--format', 'json']);
	Assert::contains('"risky": true', $out);
	Assert::contains('"riskyDeferred": 1', $out);

	// a comment silences it like any other violation
	file_put_contents("$root/src/r.php", "<?php\n\$r; // dresscode:ignore test/risky-rename\n");
	Assert::same(0, runApp($root, ['check', '--config', $config])[0]);
});
