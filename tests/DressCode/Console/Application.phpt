<?php declare(strict_types=1);

use DressCode\Console\Application;
use DressCode\{Decision, Domain, NodeRule, Plugin, PluginManifest, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo(Stage::Structure)]
final class ConsoleRename extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.rename', Domain::state('forbidden'), '`$a` is `$b`')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
			if ($context->report($node, 'Rename $a.')) {
				$node->name->setText('$b');
			}
		}
	}
}


#[RuleInfo(Stage::Structure)]
final class ConsoleRiskyRename extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.riskyRename', Domain::state('forbidden'), '`$r` is `$s`')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$r') {
			if ($context->report($node, 'Rename $r.', risk: Risk::BehaviorChanges)) {
				$node->name->setText('$s');
			}
		}
	}
}


#[RuleInfo(Stage::Formatting)]
final class ConsoleReport extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.report', Domain::state('forbidden'), 'A variable is reported')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'Seen.', fixable: false);
	}
}


#[RuleInfo(Stage::Formatting)]
final class ConsoleFailing extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('project.failing', Domain::state('forbidden'), 'A variable breaks the rule')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		throw new LogicException('Broken rule');
	}
}


/** the rules of the project every configuration of this file names */
const ConsoleRules = [ConsoleRename::class, ConsoleRiskyRename::class, ConsoleReport::class, ConsoleFailing::class];


final class ConsolePlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest;
	}
}


/** A project of its own with the configuration most tests run with, src/a.php to rename and src/b.php clean. */
function createConsoleProject(): string
{
	$root = createTempDir('console');
	mkdir("$root/src");
	// the target is pinned, so that a rule of a newer PHP has something to be newer than
	file_put_contents("$root/dresscode.php", "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden']], paths: ['src'], targets: ['php' => '8.3']);\n");
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	return $root;
}


$root = createConsoleProject();


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
	Assert::same(3, $code);
	Assert::match('DRESS%a%CODE %a%' . "\n\nUsage:%a%\n\nA dress code for PHP: %A%", $out);
	[$code, $out] = runApp($root, ['--help']);
	Assert::same(0, $code);
	Assert::match('DRESS%a%CODE %a%' . "\n\nUsage:%a%\n\nA dress code for PHP: %A%", $out);
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
		  error  2:1  Rename $a.  project.rename

		FOUND  1 violation, a fix leaves none in 1 of 2 files

		XX, $out);
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/src/a.php"));
});


test('a run under Xdebug warns that it is slower, in the formats a person reads', function () use ($root) {
	Assert::same("Warning: Xdebug is loaded and makes the run many times slower.\n", runApp($root, ['check'], xdebug: true)[2]);
	Assert::same('', runApp($root, ['check', '--format', 'json'], xdebug: true)[2]);
});


test('check of a clean path with options from the command line', function () use ($root) {
	[$code, $out] = runApp($root, ['check', 'src/b.php', '--set', 'project.rename=keep']);
	Assert::same(0, $code);
	Assert::match("%A%OK  1 file, up to the dress code\n", $out);
	[$code, $out] = runApp($root, ['check', 'src/b.php', '--set', 'project.report=forbidden', '--format', 'json']);
	Assert::same(1, $code);
	Assert::match('%A%"decision": "project.report",%A%', $out);
});


test('paths named are relative to the working directory, and a directory holding the configured paths is narrowed to them', function () {
	$root = createConsoleProject();
	mkdir("$root/other");
	file_put_contents("$root/other/c.php", "<?php\n\$a;\n");
	[, $out] = runApp("$root/src", ['check', 'a.php']);
	Assert::match("%A%Checking   %a%src%a%a.php\n%A%", $out);
	[, $out] = runApp("$root/other", ['check', '.']);
	Assert::match("%A%Checking   %a%other%a%c.php\n%A%", $out);
	[, $out] = runApp("$root/src", ['check', '..']);
	Assert::match("%A%Checking   2 files in %a%src, narrowed to the configured paths\n%A%", $out);
	[, $out] = runApp($root, ['check', 'src', 'src']);
	Assert::match("%A%Checking   2 files in %a%src\n%A%", $out);
	if (PHP_OS_FAMILY === 'Windows') { // the root is spelled as the disk has it, the working directory as it was typed
		[, $out] = runApp(strtoupper("$root/src"), ['check', '..']);
		Assert::match("%A%, narrowed to the configured paths\n%A%", $out);
	}
});


test('a path outside the root is checked where it is', function () use ($root) {
	$outside = createTempDir('console-outside');
	file_put_contents("$outside/c.php", "<?php\n\$x;\n");
	[$code, $out] = runApp($root, ['check', "$outside/c.php"]);
	Assert::same(0, $code);
	Assert::match("%A%Checking   %a%console-outside%a%c.php\n%A%", $out);
});


test('stdin: check reports, fix writes the result to stdout', function () use ($root) {
	[$code, $out] = runApp($root, ['check', '--stdin', 'src/x.php'], "<?php\n\$a;\n");
	Assert::same(1, $code);
	Assert::match("%a%x.php\n  error  2:1  Rename \$a.  project.rename\n\nFOUND  1 violation, a fix leaves none in 1 file\n", $out);
	[$code, $out, $err] = runApp($root, ['fix', '--stdin', 'src/x.php'], "<?php\n\$a;\n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$b;\n", $out);
	Assert::match("%a%x.php  rewritten\n\nFIXED  1 violation found, none remaining in 1 file\n", $err);
});


test('errors go to stderr with exit code 3', function () use ($root) {
	[$code, $out, $err] = runApp($root, ['check', '--nope']);
	Assert::same(3, $code);
	Assert::same('', $out);
	Assert::match("Error: Unknown option --nope.\n\nUsage:%A%", $err);
	[$code, , $err] = runApp($root, ['check', '--use', 'test/none=true']);
	Assert::same(3, $code);
	Assert::match('Error: Option `--use` takes a preset or a plugin, `test/none=true` given; a decision is set by `--set path=value`.%A%', $err);
	[$code, , $err] = runApp($root, ['check', '--use', 'test/nonee']);
	Assert::same(3, $code);
	Assert::same("Error: The command line: Unknown preset `test/nonee`.\n", $err);
	[$code, , $err] = runApp($root, ['check', '--format', 'xml']);
	Assert::same(3, $code);
	Assert::match("Error: Option --format: expects console, bare, github, json or checkstyle, 'xml' given.%A%", $err);
	[$code, , $err] = runApp($root, ['check', '--set', 'project.rename={']);
	Assert::same(3, $code);
	Assert::match('Error: Option `--set` has an invalid value in `project.rename={`:%A%', $err);
	[$code, , $err] = runApp($root, ['check', '--set', 'project.rename=off']);
	Assert::same(3, $code);
	Assert::match('Error: %A%project.rename%A%', $err);
	[$code, , $err] = runApp($root, ['check', '--config', "$root/none.php"]);
	Assert::same(3, $code);
	Assert::match('Error: Configuration file `%a%` does not exist.%A%', $err);
	[$code, , $err] = runApp($root, ['wat']);
	Assert::same(3, $code);
	Assert::match("Error: Unknown command 'wat'.%A%", $err);
});


test('bare says what is left to the user and which files it rewrote', function () {
	$root = createConsoleProject();
	file_put_contents("$root/src/q.php", "<?php\n\$a;\n");
	[$code, $out] = runApp($root, ['check', '--format', 'bare', 'src/q.php']);
	Assert::same(1, $code);
	Assert::match("src%a%q.php\n  error  2:1  Rename \$a.  project.rename\n", $out);
	[$code, $out] = runApp($root, ['check', '-f', 'bare', 'src/b.php']);
	Assert::same(0, $code);
	Assert::same('', $out); // a clean run says nothing at all
	[$code, $out] = runApp($root, ['fix', '-f', 'bare', 'src/q.php']);
	Assert::same(0, $code);
	Assert::match("src%a%q.php  rewritten\n", $out); // the hook has to know its file has changed
	[$code, $out] = runApp($root, ['fix', '-f', 'bare', 'src/q.php']);
	Assert::same(0, $code);
	Assert::same('', $out);
});


test('a named file the configuration excludes is checked unless the caller asks to skip it', function () {
	$root = createConsoleProject();
	mkdir("$root/vendor");
	file_put_contents("$root/vendor/v.php", "<?php\n\$a;\n");
	[$code, $out] = runApp($root, ['check', '-f', 'bare', 'vendor/v.php']);
	Assert::same(1, $code);
	Assert::match("vendor%a%v.php\n  error  2:1  Rename \$a.  project.rename\n", $out);
	[$code, $out] = runApp($root, ['fix', 'vendor/v.php', '--skip-excluded']);
	Assert::same(0, $code);
	Assert::match("%A%Nothing to fix: no file in %a%v.php\n", $out);
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/vendor/v.php"));
});


test('a run inside GitHub Actions annotates without being told to', function () use ($root) {
	putenv('GITHUB_ACTIONS=true');
	putenv("GITHUB_WORKSPACE=$root");
	[$code, $out] = runApp($root, ['check']);
	Assert::same(1, $code);
	Assert::match("%A%::error file=src/a.php,line=2,col=1,title=project.rename::Rename \$a.\n1 violation in 2 files\n", $out);
	[$code, $out] = runApp($root, ['check', '--format', 'console']);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  1 violation%A%', $out);
	[$code, $out] = runApp($root, ['check', '--stdin', 'src/x.php'], "<?php\n\$a;\n"); // an editor asked, not the workflow
	Assert::same(1, $code);
	Assert::match('%A%FOUND  1 violation%A%', $out);
	putenv('GITHUB_ACTIONS');
	putenv('GITHUB_WORKSPACE');
});


test('without a configuration file neither check nor fix runs', function () {
	$dir = sys_get_temp_dir();
	foreach (['check', 'fix'] as $command) {
		[$code, , $err] = runApp($dir, [$command]);
		Assert::same(3, $code);
		Assert::match("Error: No `dresscode.neon` or `dresscode.php` found in `%a%` or above it, so there is no dress code to check against.\n", $err);
	}
});


test('fix writes the files and reports what remains', function () use ($root) {
	[$code, $out] = runApp($root, ['fix', '--diff', '--set', 'project.report=forbidden']);
	Assert::same(1, $code);
	Assert::match(<<<'XX'
		%A%
		%a%a.php  rewritten
		  error  2:1  Seen.  project.report
		--- src/a.php
		+++ src/a.php
		@@ -1,2 +1,2 @@
		 <?php
		-$a;
		+$b;

		%a%b.php
		  error  2:1  Seen.  project.report

		FIXED  3 violations found, 2 remaining in 2 files

		XX, $out);
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/src/a.php"));
});


test('exit codes: violations, warnings, the warning threshold, a syntax error and a failing rule', function () {
	$root = createConsoleProject();
	$config = "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden', 'report' => 'forbidden']], paths: ['src']";
	$write = fn(string $tail) => file_put_contents("$root/exit.php", "$config$tail);\n");
	/** @param list<string> $args */
	$run = fn(array $args = []) => runApp($root, array_values(['check', '--config', "$root/exit.php", ...$args]))[0];

	$write('');
	Assert::same(1, $run()); // violations of both rules
	Assert::same(1, runApp($root, ['fix', '--config', "$root/exit.php"])[0]); // ConsoleReport fixes nothing
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	// the same violations as warnings: reported, counted, and the exit code stays clean
	$write(', warnOnly: [ConsoleRename::class, ConsoleReport::class]');
	[$code, $out] = runApp($root, ['check', '--config', "$root/exit.php"]);
	Assert::same(0, $code);
	Assert::match('%A%  warning  2:1  Rename $a.  project.rename%A%FOUND  3 warnings, a fix leaves 2 in 2 files%A%', $out);
	Assert::same(0, $run(['--max-warnings', '3']));
	Assert::same(1, $run(['--max-warnings', '2']));
	// and so it is over stdin
	/** @param list<string> $args */
	$stdin = fn(array $args) => runApp($root, array_values(['check', '--config', "$root/exit.php", ...$args, '--stdin', 'src/b.php']), "<?php\n\$x;\n")[0];
	Assert::same(0, $stdin([]));
	Assert::same(1, $stdin(['--max-warnings', '0']));

	// a rule left as an error decides the exit code whatever the threshold says
	$write(', warnOnly: [ConsoleReport::class]');
	Assert::same(1, $run(['--max-warnings', '100']));

	// a syntax error is 1 even when every rule only warns
	$write(', warnOnly: [ConsoleRename::class, ConsoleReport::class]');
	Assert::same(0, $run());
	file_put_contents("$root/src/broken.php", "<?php\n\$a = ;\n");
	Assert::same(1, $run());
});


test('exit codes: a file that failed is 2, a mistake of the command line or of the configuration is 3', function () {
	$root = createConsoleProject();
	file_put_contents("$root/failing.php", "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['failing' => 'forbidden']], paths: ['src']);\n");
	[$code, $out, $err] = runApp($root, ['check', '--config', "$root/failing.php"]);
	Assert::same(2, $code);
	Assert::same('', $err);
	Assert::contains('Broken rule', $out);
	// over stdin too, and the document of the format is whole
	[$code, $out] = runApp($root, ['check', '--config', "$root/failing.php", '--format', 'json', '--stdin', 'src/a.php'], "<?php\n\$a;\n");
	Assert::same(2, $code);
	Assert::contains('Broken rule', json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR)['files'][0]['failure']['message']);
	[$code, $out] = runApp($root, ['fix', '--config', "$root/failing.php", '--stdin', 'src/a.php'], "<?php\n\$a;\n");
	Assert::same(2, $code);
	Assert::same("<?php\n\$a;\n", $out);
	// an error of the tool itself
	file_put_contents("$root/throwing.php", "<?php\nthrow new LogicException('boom');\n");
	[$code, , $err] = runApp($root, ['check', '--config', "$root/throwing.php"]);
	Assert::same(2, $code);
	Assert::match("Error: boom\nLogicException in %a%throwing.php:2\n#0 %A%", $err);

	file_put_contents("$root/invalid.php", "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden']], paths: ['src'], ruleUrl: 1);\n");
	Assert::same(3, runApp($root, ['check', '--config', "$root/invalid.php"])[0]);
	Assert::same(3, runApp($root, ['check', '--nope'])[0]);
	foreach ([['--max-warnings', 'xyz'], ['--max-warnings', '-1']] as [$option, $value]) {
		[$code, , $err] = runApp($root, ['check', $option, $value]);
		Assert::same(3, $code, "$option $value");
		Assert::match("Error: Option $option: expects %a% given.%A%", $err);
	}
});


test('a risky fix waits for the run to allow it, and is a violation until it is made', function () {
	$root = createTempDir('risky');
	mkdir("$root/src");
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$config = "$root/risky.php";
	$write = fn(string $tail) => file_put_contents($config, "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['riskyRename' => 'forbidden']], paths: ['src']$tail);\n");

	// refused: reported, counted apart, not fixed, and the exit code says the code is not clean
	$write('');
	[$code] = runApp($root, ['fix', '--config', $config]);
	Assert::same(1, $code);
	Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));

	// the flag of the run allows it
	[$code] = runApp($root, ['fix', '--config', $config, '--fix-risky']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));

	// and so does the configuration, for the rules it names
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write(', fixRisky: [ConsoleRiskyRename::class]');
	Assert::same(0, runApp($root, ['fix', '--config', $config])[0]);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));

	// the JSON says of every violation why it was risky and how many are waiting
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write('');
	[, $out] = runApp($root, ['check', '--config', $config, '--format', 'json']);
	Assert::contains('"risk": "behaviorChanges"', $out);
	Assert::contains('"refused": 1', $out);

	// a comment silences it like any other violation
	file_put_contents("$root/src/r.php", "<?php\n\$r; // dresscode:ignore project.riskyRename\n");
	Assert::same(0, runApp($root, ['check', '--config', $config])[0]);
});
