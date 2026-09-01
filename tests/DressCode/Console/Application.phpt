<?php declare(strict_types=1);

use DressCode\Console\Application;
use DressCode\Rule;
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
final class ConsoleRename extends Rule
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


#[RuleInfo('test/report', Stage::Formatting)]
final class ConsoleReport extends Rule
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
file_put_contents("$root/dresscode.php", "<?php\nreturn DressCode\\Config::create()->enable(ConsoleRename::class)->paths(['src']);\n");
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

	$code = (new Application($out, $err, $in, $root, script: __DIR__ . '/../../../bin/dresscode'))->run(['dresscode', ...$args]);
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
	Assert::match("%A%  dresscode/eof-newline %s%Cleanup %a%\n%A%* test/rename %s%Structure  Renames \$a to \$b\n\n* enabled by the configuration\n", $out);
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
		. "\t->enable('dresscode/declaration-blank-lines', ['betweenFunctions' => 1, 'beforeFirst' => 0, 'afterLast' => 0])\n"
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
	file_put_contents("$root/baseline.php", "<?php\nreturn DressCode\\Config::create()->enable(ConsoleRename::class)->paths(['src'])->baseline('baseline.json');\n");
	[$code, $out, $err] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline', '--rule', ConsoleReport::class . '=on', '--format', 'json']);
	Assert::same(0, $code);
	Assert::same('', $out); // a machine-readable format keeps its stream to itself
	Assert::match("Baseline with %d% violations written to baseline.json.\n", $err);
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php", '--generate-baseline']);
	Assert::same(0, $code);
	Assert::same("Baseline with 1 violation written to baseline.json.\n", $out);
	Assert::match('%A%"rule": "test/rename",%A%', (string) file_get_contents("$root/baseline.json"));

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
	[$code, , $err] = runApp($root, ['check', '--generate-baseline']);
	Assert::same(2, $code);
	Assert::match('Error: Configure the baseline file with Config::baseline() first.%A%', $err);
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
