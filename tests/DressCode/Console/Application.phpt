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
	if (in_array($args[0] ?? null, ['check', 'fix'], strict: true) && !in_array('--jobs', $args, strict: true)) {
		$args = [...$args, '--jobs', '1']; // the rules of this file do not exist in a worker process
	}

	$code = new Application($out, $err, $in, $root, script: __DIR__ . '/../../../bin/dresscode', xdebug: $xdebug)->run(['dresscode', ...$args]);
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


test('paths named are relative to the working directory, and a directory holding the configured paths is narrowed to them', function () use ($root) {
	@mkdir("$root/other"); // @ - may exist
	file_put_contents("$root/other/c.php", "<?php\n\$a;\n");
	try {
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
	} finally {
		unlink("$root/other/c.php");
		rmdir("$root/other");
	}
});


test('a path outside the root is checked where it is', function () use ($root) {
	$outside = dirname($root) . '/console-outside';
	@mkdir($outside); // @ - may exist
	file_put_contents("$outside/c.php", "<?php\n\$x;\n");
	[$code, $out] = runApp($root, ['check', "$outside/c.php"]);
	Assert::same(0, $code);
	Assert::match("%A%Checking   %a%console-outside%a%c.php\n%A%", $out);
});


test('a rule needing a newer PHP than the target is left out and said aloud when asked for by name', function () use ($root) {
	[$code, $out, $err] = runApp($root, ['check', 'src/b.php', '--rule', 'dresscode/useless-parentheses-around-new=on']);
	Assert::same(0, $code);
	Assert::match('Warning: Rule dresscode/useless-parentheses-around-new needs PHP 8.4, the target is %a%; skipped.%A%', $err);
	Assert::match("%A%OK  no violations in 1 file\n", $out);
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


test('rules', function () use ($root) {
	[$code, $out] = runApp($root, ['rules']);
	Assert::same(0, $code);
	Assert::match("%A%  dresscode/eof-newline %s%Formatting %a%\n%A%* test/rename %s%Structure  Renames \$a to \$b\n\n* enabled by the configuration\n", $out);
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


test('without a configuration file the default preset applies', function () {
	$dir = sys_get_temp_dir();
	[$code, , $err] = runApp($dir, ['check']);
	Assert::same(2, $code);
	Assert::match('Error: No paths given and none configured.%A%', $err);
});


test('a baseline is generated by check over what a fix leaves and silences what it knows', function () use ($root) {
	file_put_contents("$root/baseline.php", "<?php\nreturn new DressCode\\Config(rules: [ConsoleReport::class => true], paths: ['src'], baseline: 'baseline.neon');\n");
	// a fix would still rename in src/a.php and src/c.php does not parse, so what a fix leaves is not known yet
	file_put_contents("$root/src/c.php", "<?php\n\$a = ;\n");
	[$code, , $err] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline', '--rule', ConsoleRename::class . '=on']);
	Assert::same(2, $code);
	Assert::same("Error: The baseline is generated over code a fix leaves alone: a fix would change 1 file, 1 file failed. Run fix first.\n  src/a.php\n  src/c.php\n", $err);
	Assert::false(is_file("$root/baseline.neon"));
	unlink("$root/src/c.php");
	[$code, , $err] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline', '--fix-risky']);
	Assert::same(2, $code);
	Assert::match('Error: The baseline is generated with the risky fixes the configuration accepts, not with --fix-risky.%A%', $err);

	[$code, $out, $err] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline', '--format', 'json']);
	Assert::same(0, $code);
	Assert::same('', $out); // a machine-readable format keeps its stream to itself
	Assert::same("Baseline with 2 violations written to baseline.neon.\n", $err);
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline']);
	Assert::same(0, $code);
	Assert::same("Baseline with 2 violations written to baseline.neon.\n", $out);
	Assert::match('%A%rule: test/report%A%', (string) file_get_contents("$root/baseline.neon"));

	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php"]);
	Assert::same(0, $code);
	Assert::match("%A%OK  2 violations in the baseline in 2 files\n", $out);
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php", '--rule', ConsoleRename::class . '=on', '--format', 'json']);
	Assert::same(1, $code);
	Assert::match('%A%"baselined": 2%A%"warnings": []%A%', $out);

	file_put_contents("$root/src/a.php", "<?php\n");
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php"]);
	Assert::same(0, $code);
	Assert::match("%A%Warning: 1 entry of the baseline no longer matches a violation; regenerate it with 'check --generate-baseline'.\n\nOK  1 violation in the baseline in 2 files\n", $out);
	// a run narrowed to another rule says nothing about the entries of this one
	[, $out] = runApp($root, [
		'check',
		'--config', "$root/baseline.php",
		'--rule', ConsoleRename::class . '=on',
		'--only', ConsoleRename::class,
		'--format', 'json',
	]);
	Assert::match('%A%"warnings": []%A%', $out);
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	[$code, , $err] = runApp($root, ['fix', '--config', "$root/baseline.php", '--generate-baseline']);
	Assert::same(2, $code);
	Assert::match("Error: Option --generate-baseline belongs to command 'check'.%A%", $err);
	// with no baseline named it lands beside the configuration, in its format
	file_put_contents("$root/report.php", "<?php\nreturn new DressCode\\Config(rules: [ConsoleReport::class => true], paths: ['src']);\n");
	[$code, $out] = runApp($root, ['check', '--config', "$root/report.php", '--generate-baseline']);
	Assert::same(0, $code);
	Assert::match("Baseline with 2 violations written to dresscode-baseline.php.\nName it under 'baseline' in the configuration to make it apply.\n", $out);
	Assert::match("%A%'rule' => 'test/report',%A%", (string) file_get_contents("$root/dresscode-baseline.php"));
	unlink("$root/dresscode-baseline.php");
});


test('workers give the same results as the in-process run', function () use ($root) {
	file_put_contents("$root/jobs.php", "<?php\nreturn new DressCode\\Config(rules: ['dresscode/no-trailing-whitespace' => true], paths: ['jobs']);\n");
	@mkdir("$root/jobs");
	file_put_contents("$root/jobs/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/jobs/b.php", "<?php\n\$x;\n");
	file_put_contents("$root/jobs/c.php", "<?php\n\$a; \n\$a;\t\n");
	$config = ['--config', "$root/jobs.php", '--no-cache'];
	[, $expected] = runApp($root, ['check', ...$config, '--jobs', '1']);
	[$code, $out, $err] = runApp($root, ['check', ...$config, '--jobs', '2']);
	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::same($expected, $out);
	Assert::match("%A%a.php\n%A%c.php\n%A%FOUND  3 violations, a fix leaves none in 2 of 3 files\n", $out);

	[$code, $out] = runApp($root, ['fix', ...$config, '--jobs', '2']);
	Assert::same(0, $code);
	Assert::match("%A%a.php  rewritten\n%A%c.php  rewritten\n\nFIXED  3 violations found, none remaining in 2 of 3 files\n", $out);
	Assert::same("<?php\n\$a;\n\$a;\n", file_get_contents("$root/jobs/c.php"));
	[$code, $out] = runApp($root, ['check', ...$config, '--jobs', '2']);
	Assert::same(0, $code);
	Assert::match("%A%OK  no violations in 3 files\n", $out);
});


test('a baseline generated by workers is the one the single process writes, and it does not stand in the way of a fix', function () use ($root) {
	$a = "<?php\nin_array(\$a, \$b);\n";
	$c = "<?php\nin_array(\$a, \$b);\nin_array(\$a, \$b);\n";
	file_put_contents("$root/jobs/a.php", $a);
	file_put_contents("$root/jobs/c.php", $c);
	file_put_contents("$root/jobs.php", "<?php\nreturn new DressCode\\Config(rules: ['dresscode/strict-call' => true], paths: ['jobs'], baseline: 'jobs-baseline.neon');\n");
	$config = ['--config', "$root/jobs.php", '--no-cache'];

	// the risky fixes the configuration does not accept are what a fix leaves
	runApp($root, ['check', ...$config, '--generate-baseline', '--jobs', '1']);
	$single = (string) file_get_contents("$root/jobs-baseline.neon");
	runApp($root, ['check', ...$config, '--generate-baseline', '--jobs', '3']);
	Assert::same($single, (string) file_get_contents("$root/jobs-baseline.neon"));
	Assert::match('%A%rule: dresscode/strict-call%A%', $single);

	// what the baseline knows is not reported, and the fix the run does not allow is not made
	[$code, $out] = runApp($root, ['fix', ...$config, '--jobs', '3']);
	Assert::same(0, $code);
	Assert::match("%A%OK  3 violations in the baseline in 3 files\n", $out);
	Assert::same($a, (string) file_get_contents("$root/jobs/a.php"));

	// once the run allows it, the fix is made, with workers as without them
	foreach (['1', '3'] as $jobs) {
		file_put_contents("$root/jobs/a.php", $a);
		file_put_contents("$root/jobs/c.php", $c);
		[$code] = runApp($root, ['fix', ...$config, '--fix-risky', '--jobs', $jobs]);
		Assert::same(0, $code);
		Assert::same("<?php\nin_array(\$a, \$b, true);\n", (string) file_get_contents("$root/jobs/a.php"));
	}

	unlink("$root/jobs-baseline.neon");
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


test('config says what every rule ends up with, where it came from and why one does not run', function () use ($root) {
	file_put_contents("$root/conf.neon", <<<'XX'
		presets:
			- dresscode/psr12

		lineLength: 100

		rules:
			dresscode/line-length: {ignoreImports: false}
			dresscode/name-casing: keep
			dresscode/ordered-imports: {order: alphabetical}

		overrides:
			- paths: [src/generated]
			  rules: {dresscode/indentation: keep}

		paths: [src]

		XX);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon"]);
	Assert::same(0, $code);
	Assert::match('%A%Presets    dresscode/psr12%A%', $out);
	Assert::match('%A%  dresscode/line-length %a%the configuration%A%', $out);
	Assert::match('%A%Style%a%4 spaces, the line ending each file mostly has, lines of up to 100 characters%A%', $out);
	Assert::match('%A%      ignoreImports %a%false %a%the configuration%A%', $out);
	// a value the project changed says what it overrode, one that only repeats the preset does not
	Assert::match('%A%      order %a%alphabetical %a%the configuration (over dresscode/psr12 byKind)%A%', $out);
	Assert::match('%A%Not running%A%  dresscode/name-casing %a%turned off by the configuration%A%', $out);
	Assert::notContains('dresscode/indentation ', substr($out, strpos($out, 'Not running') ?: 0));

	// for one file it is what the run uses for that file
	[, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--file', 'src/generated/x.php']);
	Assert::match('%A%File       src%a%generated%a%x.php%A%', $out);
	Assert::match('%A%  dresscode/indentation %a%turned off by the override for src/generated%A%', $out);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--json']);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true);
	Assert::same(['dresscode/psr12'], $data['presets']);
	Assert::same(100, $data['lineLength']);
	Assert::false($data['rules']['dresscode/line-length']['options']['ignoreImports']);
	Assert::false($data['rules']['dresscode/name-casing']['active']);
	Assert::same('turned off by the configuration', $data['rules']['dresscode/name-casing']['inactive']);
	Assert::same(
		[
			['source' => 'dresscode/psr12', 'value' => 'byKind'],
			['source' => 'the configuration', 'value' => 'alphabetical'],
		],
		$data['rules']['dresscode/ordered-imports']['origins']['order'],
	);
});


test('overrides: another part of the tree gets other rules, and the run, the cache and the workers agree', function () use ($root) {
	@mkdir("$root/lib");
	@mkdir("$root/legacy");
	foreach (['lib/a.php', 'legacy/b.php', 'legacy/e.php', 'legacy/deep/c.php'] as $path) {
		@mkdir(dirname("$root/$path"), recursive: true);
		file_put_contents("$root/$path", "<?php\n\$a;\n");
	}

	file_put_contents("$root/overrides.neon", <<<'XX'
		rules:
			dresscode/no-trailing-whitespace: true

		overrides:
			- paths: [legacy]
			  rules: {dresscode/no-trailing-whitespace: keep, dresscode/eof-newline: true}
			- paths: [legacy/deep]
			  rules: {dresscode/eof-newline: keep}

		paths: [lib, legacy]
		cacheDir: cache

		XX);
	$config = ['--config', "$root/overrides.neon", '--no-cache'];

	// what the configuration comes to for a file is what the overrides it matches say, in the order written
	$names = function (string $file) use ($root): array {
		[, $out] = runApp($root, ['config', '--config', "$root/overrides.neon", '--file', $file, '--json']);
		$data = json_decode($out, associative: true);
		return array_keys(array_filter($data['rules'], fn(array $rule) => $rule['active']));
	};
	Assert::same(['dresscode/no-trailing-whitespace'], $names('lib/a.php'));
	Assert::same(['dresscode/eof-newline'], $names('legacy/b.php')); // the override turned the first rule off
	Assert::same([], $names('legacy/deep/c.php')); // and the second override turned the other one off

	// the file of an override is processed with its rules; without one it keeps the base
	$dirty = function () use ($root): void {
		file_put_contents("$root/lib/a.php", "<?php\n\$a; \n");
		file_put_contents("$root/legacy/b.php", "<?php\n\$a; \n");
		file_put_contents("$root/legacy/e.php", "<?php\n\$a;");
		file_put_contents("$root/legacy/deep/c.php", "<?php\n\$a;");
	};
	$state = fn(): array => array_map(
		fn(string $path) => (string) file_get_contents("$root/$path"),
		['lib/a.php', 'legacy/b.php', 'legacy/e.php', 'legacy/deep/c.php'],
	);
	$fixed = [
		"<?php\n\$a;\n",   // lib: no-trailing-whitespace ran
		"<?php\n\$a; \n",  // legacy: it did not
		"<?php\n\$a;\n",   // legacy: eof-newline did
		"<?php\n\$a;",     // legacy/deep: the second override turned that one off too
	];

	$dirty();
	[$code] = runApp($root, ['fix', ...$config]);
	Assert::same(0, $code);
	Assert::same($fixed, $state());

	// workers see the same overrides as the one process
	$dirty();
	[$code] = runApp($root, ['fix', ...$config, '--jobs', '3']);
	Assert::same(0, $code);
	Assert::same($fixed, $state());

	// stdin stands for the path it is given, overrides and all
	[$code, $out] = runApp($root, ['fix', ...$config, '--stdin', 'legacy/x.php'], "<?php\n\$a; \n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$a; \n", $out);
	[$code, $out] = runApp($root, ['fix', ...$config, '--stdin', 'lib/x.php'], "<?php\n\$a; \n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$a;\n", $out);
	[, $out] = runApp("$root/legacy", ['fix', ...$config, '--stdin', 'x.php'], "<?php\n\$a; \n"); // relative to the working directory
	Assert::same("<?php\n\$a; \n", $out);

	// the cache tells the two configurations of one content apart: the same text is clean in one and not
	// in the other, and a warm run says what the cold one said
	file_put_contents("$root/lib/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/legacy/b.php", "<?php\n\$a; \n");
	foreach ([1, 2] as $round) {
		[$code, $out] = runApp($root, ['check', '--config', "$root/overrides.neon"]);
		Assert::same(1, $code, "round $round");
		Assert::match('%A%lib%a%a.php%A%', $out);
		Assert::notContains('b.php', $out);
	}
});


test('--only narrows the run to what it names, in the workers and in the cache too', function () use ($root) {
	@mkdir("$root/only");
	foreach (['a', 'b', 'c', 'd'] as $name) {
		file_put_contents("$root/only/$name.php", "<?php\n\$a; \n\$b;"); // trailing whitespace, no newline at the end
	}

	file_put_contents("$root/only.neon", <<<'XX'
		rules:
			dresscode/no-trailing-whitespace: true
			dresscode/eof-newline: true

		paths: [only]
		cacheDir: cache

		XX);
	$config = ['--config', "$root/only.neon"];

	// check reports what the named rule reports and nothing else, with workers as without them
	[$code, $expected] = runApp($root, ['check', ...$config, '--only', 'no-trailing-whitespace', '--no-cache']);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  4 violations, a fix leaves none in 4 files%A%', $expected);
	Assert::notContains('eof-newline', $expected);
	[, $out] = runApp($root, ['check', ...$config, '--only', 'no-trailing-whitespace', '--no-cache', '--jobs', '2']);
	Assert::same($expected, $out);

	// fix changes what that rule reports and leaves the rest alone
	[$code] = runApp($root, ['fix', ...$config, '--only', 'no-trailing-whitespace', '--jobs', '2']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$a;\n\$b;", file_get_contents("$root/only/a.php"));

	// what the narrowed run remembered as clean is not clean for the whole configuration
	Assert::same(0, runApp($root, ['check', ...$config, '--only', 'no-trailing-whitespace'])[0]);
	[$code, $out] = runApp($root, ['check', ...$config]);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  4 violations, a fix leaves none in 4 files%A%', $out);
	Assert::contains('eof-newline', $out);

	// config says of every other rule why it does not run
	[, $out] = runApp($root, ['config', ...$config, '--only', 'no-trailing-whitespace']);
	Assert::match('%A%Not running%A%  dresscode/eof-newline %s%the run is narrowed to other rules%A%', $out);

	// a baseline written by a narrowed run would forget what every other rule found
	[$code, , $err] = runApp($root, ['check', ...$config, '--only', 'eof-newline', '--generate-baseline']);
	Assert::same(2, $code);
	Assert::match('Error: The baseline is generated by the whole configuration, not with --only.%A%', $err);
});


test('exit codes: violations, warnings, the warning threshold, a syntax error and a failing rule', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	$config = "<?php\nreturn new DressCode\\Config(rules: [ConsoleRename::class => true, ConsoleReport::class => true], paths: ['src']";
	$write = fn(string $tail) => file_put_contents("$root/exit.php", "$config$tail);\n");
	/** @param list<string> $args */
	$run = fn(array $args = []) => runApp($root, array_values(['check', '--config', "$root/exit.php", '--no-cache', ...$args]))[0];

	$write('');
	Assert::same(1, $run()); // violations of both rules
	Assert::same(1, runApp($root, ['fix', '--config', "$root/exit.php", '--no-cache'])[0]); // test/report fixes nothing
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	// the same violations as warnings: reported, counted, and the exit code stays clean
	$write(', warnings: [ConsoleRename::class, ConsoleReport::class]');
	[$code, $out] = runApp($root, ['check', '--config', "$root/exit.php", '--no-cache']);
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
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache']);
	Assert::same(1, $code);
	Assert::contains('1 of them risky (test/risky-rename), fixed once their rules are named in fixRisky or with --fix-risky', $out);
	Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));

	// the flag of the run allows it
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--fix-risky']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));
	Assert::notContains('of them risky', $out);

	// and so does the configuration, for the rules it names
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write(', fixRisky: [ConsoleRiskyRename::class]');
	Assert::same(0, runApp($root, ['fix', '--config', $config, '--no-cache'])[0]);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));

	// the JSON says of every violation whether it was risky and how many are waiting
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write('');
	[, $out] = runApp($root, ['check', '--config', $config, '--no-cache', '--format', 'json']);
	Assert::contains('"risky": true', $out);
	Assert::contains('"riskyDeferred": 1', $out);

	// a comment silences it like any other violation
	file_put_contents("$root/src/r.php", "<?php\n\$r; // dresscode:ignore test/risky-rename\n");
	Assert::same(0, runApp($root, ['check', '--config', $config, '--no-cache'])[0]);
});


test('a violation the rule has no fix for is no fix waiting, with the consent or without it', function () use ($root) {
	Helpers::purge("$root/src");
	$config = "$root/strict-call.php";
	// an explicit false is only reported, so neither the consent nor its absence has a fix to make
	foreach ([
		['in_array(1, [1], false)', '', 1, 0],
		['in_array(1, [1], false)', ", fixRisky: ['strict-call']", 1, 0],
		['in_array(1, [1])', '', 1, 1],
		['in_array(1, [1])', ", fixRisky: ['strict-call']", 0, 0],
	] as [$call, $tail, $remaining, $deferred]) {
		file_put_contents("$root/src/a.php", "<?php\n$call;\n");
		file_put_contents($config, "<?php\nreturn new DressCode\\Config(rules: ['strict-call' => true], paths: ['src']$tail);\n");

		[, $out] = runApp($root, ['check', '--config', $config, '--no-cache', '--format', 'json']);
		$summary = json_decode($out, associative: true)['summary'];
		Assert::same([$remaining, $deferred], [$summary['remaining'], $summary['riskyDeferred']], "$call$tail");

		[, $out] = runApp($root, ['check', '--config', $config, '--no-cache']);
		if ($deferred) {
			Assert::contains('1 of them risky (strict-call)', $out);
		} else {
			Assert::notContains('of them risky', $out);
		}
	}
});


test('config says of a rule with risky fixes whether the project accepts them, and a name that does nothing is a warning', function () use ($root) {
	$config = "$root/risky-config.php";
	file_put_contents($config, "<?php\nreturn new DressCode\\Config(rules: [ConsoleRiskyRename::class => true, 'strict-call' => true],"
		. " overrides: [new DressCode\\Override(['src'], rules: ['static-closure' => true])], fixRisky: [ConsoleRiskyRename::class, 'static-closure', 'final-internal-class'], paths: ['src']);\n");

	[$code, $out] = runApp($root, ['config', '--config', $config]);
	Assert::same(0, $code);
	Assert::match('%A%  test/risky-rename %a%the configuration, risky fixes accepted%A%', $out);
	Assert::match('%A%  dresscode/strict-call %a%the configuration, risky fixes only reported%A%', $out);
	Assert::match('%A%Not running%A%  dresscode/static-closure %s%only an override turns it on, risky fixes accepted%A%', $out);
	Assert::match('%A%Not running%A%  dresscode/final-internal-class %s%no preset or rule of the configuration mentions it, risky fixes accepted%A%', $out);

	[, $out] = runApp($root, ['config', '--config', $config, '--json']);
	$rules = json_decode($out, associative: true)['rules'];
	Assert::true($rules['test/risky-rename']['fixRisky']);
	Assert::false($rules['dresscode/strict-call']['fixRisky']);

	// a rule an override turns on runs somewhere, one that nothing turns on makes the entry a line that does nothing
	[, , $err] = runApp($root, ['check', '--config', $config, '--no-cache']);
	Assert::contains('Rule dresscode/final-internal-class is named in fixRisky but runs nowhere; the entry does nothing.', $err);
	Assert::notContains('static-closure', $err);
});
