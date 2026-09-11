<?php declare(strict_types=1);

use DressCode\Console\Application;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Token;
use Tester\Assert;
use Tester\Helpers;


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
file_put_contents("$root/dresscode.php", "<?php\nreturn DressCode\\Config::create()->enable(ConsoleRename::class)->paths(['src'])->php('8.3');\n");
file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
file_put_contents("$root/src/b.php", "<?php\n\$x;\n");


/**
 * @param  list<string>  $args
 * @return array{int, string, string}
 */
function runApp(string $root, array $args, string $stdin = ''): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$in = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	fwrite($in, $stdin);
	rewind($in);
	if (!in_array('--jobs', $args, strict: true)) {
		$args = [...$args, '--jobs', '1']; // the rules of this file do not exist in a worker process
	}

	$code = new Application($out, $err, $in, $root, script: __DIR__ . '/../../../bin/dresscode')->run(['dresscode', ...$args]);
	rewind($out);
	rewind($err);
	return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
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

		FOUND  1 violation, 1 of them fixable in 1 of 2 files

		XX, $out);
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/src/a.php"));
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
	Assert::match("%A%Checking   1 file in %a%console-outside\n%A%", $out);
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
	Assert::match("%a%x.php\n  error  2:1  Rename \$a  test/rename\n\nFOUND  1 violation, 1 of them fixable in 1 file\n", $out);
	[$code, $out, $err] = runApp($root, ['fix', '--stdin', 'src/x.php'], "<?php\n\$a;\n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$b;\n", $out);
	Assert::match("%a%x.php\n  fixed  2:1  Rename \$a  test/rename\n\nFIXED  1 violation fixed in 1 file\n", $err);
});


test('rules', function () use ($root) {
	[$code, $out] = runApp($root, ['rules']);
	Assert::same(0, $code);
	Assert::match("%A%  dresscode/eof-newline %s%Formatting %a%\n%A%* test/rename %s%Structure  Renames \$a to \$b\n\n* enabled by the configuration\n", $out);
});


test('import translates a foreign configuration and says what it could not', function () use ($root) {
	file_put_contents("$root/phpcs.xml", <<<'XX'
		<?xml version="1.0"?>
		<ruleset name="Demo">
			<rule ref="PSR12"/>
			<rule ref="SlevomatCodingStandard.Arrays.TrailingArrayComma"/>
			<rule ref="SlevomatCodingStandard.Functions.RequireTrailingCommaInCall"/>
			<rule ref="Squiz.WhiteSpace.FunctionSpacing">
				<properties>
					<property name="spacing" value="1"/>
					<property name="spacingBeforeFirst" value="0"/>
					<property name="spacingAfterLast" value="0"/>
				</properties>
			</rule>
			<rule ref="Squiz.Nonsense.DoesNotExist"/>
		</ruleset>
		XX);
	[$code, $out, $err] = runApp($root, ['import', "$root/phpcs.xml"]);
	Assert::same(0, $code);
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return Config::create()\n"
		. "\t->preset('dresscode/psr12')\n"
		. "\t->enable('dresscode/blank-lines', ['betweenFunctions' => 1, 'beforeFirstFunction' => 0, 'afterLastFunction' => 0])\n"
		. "\t->enable('dresscode/trailing-comma', ['multiLine' => ['arrays', 'arguments'], 'singleLine' => false]);\n",
		$out,
	);
	Assert::same(
		"\nRead 5 rules, enabled 2 and 1 preset.\n  No DressCode rule covers Squiz.Nonsense.DoesNotExist.\n",
		$err,
	);

	file_put_contents("$root/fixer.php", "<?php\nreturn new class {\n\tpublic function getRules(): array\n\t{\n\t\treturn ['cast_spaces' => ['space' => 'none']];\n\t}\n};\n");
	[$code, $out] = runApp($root, ['import', "$root/fixer.php"]);
	Assert::same(0, $code);
	Assert::contains("->enable('dresscode/cast-spacing', ['spacing' => 'none'])", $out);

	[$code, , $err] = runApp($root, ['import']);
	Assert::same(2, $code);
	Assert::match('Error: No configuration file given.%A%', $err);
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
	Assert::match('Error: Value of option --format must be console, or bare, or github, or json, or checkstyle.%A%', $err);
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


test('a baseline is generated by check and silences what it knows', function () use ($root) {
	file_put_contents("$root/baseline.php", "<?php\nreturn DressCode\\Config::create()->enable(ConsoleRename::class)->paths(['src'])->baseline('baseline.neon');\n");
	[$code, $out, $err] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline', '--rule', ConsoleReport::class . '=on', '--format', 'json']);
	Assert::same(0, $code);
	Assert::same('', $out); // a machine-readable format keeps its stream to itself
	Assert::match("Baseline with %d% violations written to baseline.neon.\n", $err);
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline']);
	Assert::same(0, $code);
	Assert::same("Baseline with 1 violation written to baseline.neon.\n", $out);
	Assert::match('%A%rule: test/rename%A%', (string) file_get_contents("$root/baseline.neon"));

	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php"]);
	Assert::same(0, $code);
	Assert::match("%A%OK  1 violation in the baseline in 2 files\n", $out);
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php", '--rule', ConsoleReport::class . '=on', '--format', 'json']);
	Assert::same(1, $code);
	Assert::match('%A%"baselined": 1%A%"warnings": []%A%', $out);

	file_put_contents("$root/src/a.php", "<?php\n\$x;\n");
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php"]);
	Assert::same(0, $code);
	Assert::match("%A%Warning: 1 entry of the baseline no longer match a violation; regenerate it\n\nOK  no violations in 2 files\n", $out);
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	[$code, , $err] = runApp($root, ['fix', '--config', "$root/baseline.php", '--generate-baseline']);
	Assert::same(2, $code);
	Assert::match('Error: The baseline is generated by check, not by fix.%A%', $err);
	[$code, $out] = runApp($root, ['check', '--generate-baseline']); // the file of the run is dresscode.php
	Assert::same(0, $code);
	Assert::match("Baseline with 1 violation written to dresscode-baseline.php.\nName it in the configuration to make it apply.\n", $out);
	Assert::match("%A%'rule' => 'test/rename',%A%", (string) file_get_contents("$root/dresscode-baseline.php"));
	unlink("$root/dresscode-baseline.php");
});


test('workers give the same results as the in-process run', function () use ($root) {
	file_put_contents("$root/jobs.php", "<?php\nreturn DressCode\\Config::create()->enable('dresscode/no-trailing-whitespace')->paths(['jobs']);\n");
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
	Assert::match("%A%a.php\n%A%c.php\n%A%FOUND  3 violations, 3 of them fixable in 2 of 3 files\n", $out);

	[$code, $out] = runApp($root, ['fix', ...$config, '--jobs', '2']);
	Assert::same(0, $code);
	Assert::match("%A%a.php\n  fixed  %a%no-trailing-whitespace\n%A%c.php\n  fixed  %a%no-trailing-whitespace\n%A%\nFIXED  3 violations fixed in 2 of 3 files\n", $out);
	Assert::same("<?php\n\$a;\n\$a;\n", file_get_contents("$root/jobs/c.php"));
	[$code, $out] = runApp($root, ['check', ...$config, '--jobs', '2']);
	Assert::same(0, $code);
	Assert::match("%A%OK  no violations in 3 files\n", $out);
});


test('a baseline generated by workers is the one the single process writes, and it silences the fix', function () use ($root) {
	file_put_contents("$root/jobs/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/jobs/c.php", "<?php\n\$a; \n\$a;\t\n");
	file_put_contents("$root/jobs.php", "<?php\nreturn DressCode\\Config::create()->enable('dresscode/no-trailing-whitespace')->paths(['jobs'])->baseline('jobs-baseline.neon');\n");
	$config = ['--config', "$root/jobs.php", '--no-cache'];

	runApp($root, ['check', ...$config, '--generate-baseline', '--jobs', '1']);
	$single = (string) file_get_contents("$root/jobs-baseline.neon");
	runApp($root, ['check', ...$config, '--generate-baseline', '--jobs', '3']);
	Assert::same($single, (string) file_get_contents("$root/jobs-baseline.neon"));
	Assert::match('%A%rule: dresscode/no-trailing-whitespace%A%', $single);

	// what the baseline knows is not reported and not fixed, with workers as without them
	foreach (['1', '3'] as $jobs) {
		[$code, $out] = runApp($root, ['fix', ...$config, '--jobs', $jobs]);
		Assert::same(0, $code);
		Assert::match("%A%OK  3 violations in the baseline in 3 files\n", $out);
		Assert::same("<?php\n\$a; \n", (string) file_get_contents("$root/jobs/a.php"));
	}

	unlink("$root/jobs-baseline.neon");
});


test('migrate-suppressions rewrites phpcs comments to the dresscode form', function () use ($root) {
	file_put_contents("$root/src/s.php", "<?php\n// phpcs:ignoreFile\n/**\n * @phpcsSuppress Squiz.WhiteSpace.SuperfluousWhitespace\n */\nclass S\n{\n\t// phpcs:ignore Squiz.WhiteSpace.SuperfluousWhitespace, Generic.Metrics.CyclomaticComplexity -- why\n\tpublic \$a; // phpcs:disable Squiz.WhiteSpace.SuperfluousWhitespace\n\t// phpcs:enable\n}\n");
	[$code, $out, $err] = runApp($root, ['migrate-suppressions', 'src/s.php']);
	Assert::same('', $err);
	Assert::same(0, $code);
	Assert::same(
		"Migrated 5 suppression comments in 1 file.\n"
		. "Warning: unknown rule names kept as they are: Generic.Metrics.CyclomaticComplexity\n"
		. "Note: dresscode:ignore on a line of its own covers the whole statement below it, not just the next line; review the migrated ones.\n",
		$out,
	);
	Assert::same(
		"<?php\n// dresscode:ignore-file\n/**\n * @phpcsSuppress dresscode/no-trailing-whitespace\n */\nclass S\n{\n\t// dresscode:ignore dresscode/no-trailing-whitespace, Generic.Metrics.CyclomaticComplexity -- why\n\tpublic \$a; // dresscode:disable dresscode/no-trailing-whitespace\n\t// dresscode:enable\n}\n",
		file_get_contents("$root/src/s.php"),
	);
	[$code, $out] = runApp($root, ['migrate-suppressions', 'src/s.php']);
	Assert::same(0, $code);
	Assert::same("Migrated 0 suppression comments in 0 files.\n", $out);
	unlink("$root/src/s.php");
});


test('fix writes the files and reports what remains', function () use ($root) {
	[$code, $out] = runApp($root, ['fix', '--diff', '--rule', ConsoleReport::class . '=on']);
	Assert::same(1, $code);
	Assert::match(<<<'XX'
		%A%
		%a%a.php
		  fixed  2:1  Rename $a  test/rename
		  error  2:1  Seen       test/report
		--- src/a.php
		+++ src/a.php
		@@ -1,2 +1,2 @@
		 <?php
		-$a;
		+$b;

		%a%b.php
		  error  2:1  Seen  test/report

		FIXED  1 violation fixed, 2 remaining in 2 files

		XX, $out);
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/src/a.php"));
});


test('config says what every rule ends up with, where it came from and why one does not run', function () use ($root) {
	file_put_contents("$root/conf.neon", <<<'XX'
		presets:
			- dresscode/psr12

		rules:
			dresscode/line-length: {limit: 100}
			dresscode/name-casing: keep
			dresscode/ordered-imports: {order: alphabetical}

		excludeRulePaths:
			dresscode/indentation: [src/generated]

		paths: [src]

		XX);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon"]);
	Assert::same(0, $code);
	Assert::match('%A%Presets    dresscode/psr12%A%', $out);
	Assert::match('%A%  dresscode/line-length %a%the configuration%A%', $out);
	Assert::match('%A%      limit %a%100 %a%the configuration%A%', $out);
	// a value the project changed says what it overrode, one that only repeats the preset does not
	Assert::match('%A%      order %a%alphabetical %a%the configuration (over dresscode/psr12 byKind)%A%', $out);
	Assert::match('%A%Not running%A%  dresscode/name-casing %a%turned off by the configuration%A%', $out);
	Assert::notContains('dresscode/indentation ', substr($out, strpos($out, 'Not running') ?: 0));

	// for one file it is what the run uses for that file
	[, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--file', 'src/generated/x.php']);
	Assert::match('%A%File       src%a%generated%a%x.php%A%', $out);
	Assert::match('%A%  dresscode/indentation %a%the configuration keeps it away from this path%A%', $out);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--json']);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true);
	Assert::same(['dresscode/psr12'], $data['presets']);
	Assert::same(100, $data['rules']['dresscode/line-length']['options']['limit']);
	Assert::false($data['rules']['dresscode/name-casing']['active']);
	Assert::same('turned off by the configuration', $data['rules']['dresscode/name-casing']['inactive']);
	Assert::same(
		[['source' => 'dresscode/psr12', 'value' => 'byKind'], ['source' => 'the configuration', 'value' => 'alphabetical']],
		$data['rules']['dresscode/ordered-imports']['origins']['order'],
	);
});


test('for: another part of the tree gets other rules, and the run, the cache and the workers agree', function () use ($root) {
	@mkdir("$root/lib");
	@mkdir("$root/legacy");
	foreach (['lib/a.php', 'legacy/b.php', 'legacy/e.php', 'legacy/deep/c.php'] as $path) {
		@mkdir(dirname("$root/$path"), recursive: true);
		file_put_contents("$root/$path", "<?php\n\$a;\n");
	}

	file_put_contents("$root/for.neon", <<<'XX'
		rules:
			dresscode/no-trailing-whitespace: true

		for:
			- files: [legacy]
			  rules: {dresscode/no-trailing-whitespace: keep, dresscode/eof-newline: true}
			- files: [legacy/deep]
			  rules: {dresscode/eof-newline: keep}

		paths: [lib, legacy]
		cacheDir: cache

		XX);
	$config = ['--config', "$root/for.neon", '--no-cache'];

	// what the configuration comes to for a file is what the blocks it matches say, in the order written
	$names = function (string $file) use ($root, $config): array {
		[, $out] = runApp($root, ['config', ...$config, '--file', $file, '--json']);
		$data = json_decode($out, associative: true);
		return array_keys(array_filter($data['rules'], fn(array $rule) => $rule['active']));
	};
	Assert::same(['dresscode/no-trailing-whitespace'], $names('lib/a.php'));
	Assert::same(['dresscode/eof-newline'], $names('legacy/b.php')); // the block turned the first rule off
	Assert::same([], $names('legacy/deep/c.php')); // and the second block turned the other one off

	// the file of a block is processed with its rules; without one it keeps the base
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
		"<?php\n\$a;",     // legacy/deep: the second block turned that one off too
	];

	$dirty();
	[$code] = runApp($root, ['fix', ...$config]);
	Assert::same(0, $code);
	Assert::same($fixed, $state());

	// workers see the same blocks as the one process
	$dirty();
	[$code] = runApp($root, ['fix', ...$config, '--jobs', '3']);
	Assert::same(0, $code);
	Assert::same($fixed, $state());

	// stdin stands for the path it is given, blocks and all
	[$code, $out] = runApp($root, ['fix', ...$config, '--stdin', 'legacy/x.php'], "<?php\n\$a; \n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$a; \n", $out);
	[$code, $out] = runApp($root, ['fix', ...$config, '--stdin', 'lib/x.php'], "<?php\n\$a; \n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$a;\n", $out);

	// the cache tells the two configurations of one content apart: the same text is clean in one and not
	// in the other, and a warm run says what the cold one said
	file_put_contents("$root/lib/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/legacy/b.php", "<?php\n\$a; \n");
	foreach ([1, 2] as $round) {
		[$code, $out] = runApp($root, ['check', '--config', "$root/for.neon"]);
		Assert::same(1, $code, "round $round");
		Assert::match('%A%lib%a%a.php%A%', $out);
		Assert::notContains('b.php', $out);
	}
});


test('exit codes: violations, warnings, the warning threshold, a syntax error and a failing rule', function () use ($root) {
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	$config = "<?php\nreturn DressCode\\Config::create()->enable(ConsoleRename::class)->enable(ConsoleReport::class)->paths(['src'])";
	$write = fn(string $tail) => file_put_contents("$root/exit.php", "$config$tail;\n");
	/** @param list<string> $args */
	$run = fn(array $args = []) => runApp($root, array_values(['check', '--config', "$root/exit.php", '--no-cache', ...$args]))[0];

	$write('');
	Assert::same(1, $run()); // violations of both rules
	Assert::same(1, runApp($root, ['fix', '--config', "$root/exit.php", '--no-cache'])[0]); // test/report fixes nothing
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	// the same violations as warnings: reported, counted, and the exit code stays clean
	$write('->warnings([ConsoleRename::class, ConsoleReport::class])');
	[$code, $out] = runApp($root, ['check', '--config', "$root/exit.php", '--no-cache']);
	Assert::same(0, $code);
	Assert::match('%A%  warning  2:1  Rename $a  test/rename%A%FOUND  3 warnings, 1 of them fixable in 2 files%A%', $out);
	Assert::same(0, $run(['--max-warnings', '3']));
	Assert::same(1, $run(['--max-warnings', '2']));

	// a rule left as an error decides the exit code whatever the threshold says
	$write('->warnings([ConsoleReport::class])');
	Assert::same(1, $run(['--max-warnings', '100']));

	// a syntax error is 1 even when every rule only warns
	$write('->warnings([ConsoleRename::class, ConsoleReport::class])');
	file_put_contents("$root/src/broken.php", "<?php\n\$a = ;\n");
	Assert::same(1, $run());
	unlink("$root/src/broken.php");
	Assert::same(0, $run());
});


test('a risky fix waits for the run to allow it, and is a violation until it is made', function () use ($root) {
	Helpers::purge("$root/src");
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$config = "$root/risky.php";
	$write = fn(string $tail) => file_put_contents($config, "<?php\nreturn DressCode\\Config::create()->enable(ConsoleRiskyRename::class)->paths(['src'])$tail;\n");

	// refused: reported, counted apart, not fixed, and the exit code says the code is not clean
	$write('');
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache']);
	Assert::same(1, $code);
	Assert::contains('1 of them risky, run with --fix-risky to have them fixed', $out);
	Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));

	// the flag of the run allows it
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--fix-risky']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));
	Assert::notContains('--fix-risky to have them fixed', $out);

	// and so does the configuration, which says the same thing for every run
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write('->risky()');
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
