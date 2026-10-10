<?php declare(strict_types=1);

use DressCode\Console\Application;
use DressCode\{Decision, Domain, NodeRule, Plugin, PluginManifest, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\Classes\FinalForInternalClassRule;
use DressCode\Rules\Functions\StaticForClosureWithoutThisRule;
use Nette\Utils\FileSystem;
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
function runApp(string $root, array $args, string $stdin = '', bool $xdebug = false, bool $interactive = false): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$in = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	fwrite($in, $stdin);
	rewind($in);
	if (in_array($args[0] ?? null, ['check', 'fix', 'baseline'], strict: true) && !in_array('--jobs', $args, strict: true)) {
		$args = [...$args, '--jobs', '1']; // the rules of this file do not exist in a worker process
	}

	$code = new Application($out, $err, $in, $root, script: __DIR__ . '/../../../bin/dresscode', xdebug: $xdebug, interactive: $interactive)->run(['dresscode', ...$args]);
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
	[$code, $out] = runApp($root, ['config', '--set', 'project.report=forbidden', '--format', 'json']);
	Assert::same(0, $code);
	Assert::true(json_decode($out, associative: true)['rules'][ConsoleReport::class]['active']);
	// --only narrows the run to what it names, and turns nothing on
	[$code, $out] = runApp($root, ['config', '--set', 'project.report=forbidden', '--only', 'project.report', '--format', 'json']);
	Assert::same(0, $code);
	$rules = json_decode($out, associative: true)['rules'];
	Assert::true($rules[ConsoleReport::class]['active']);
	Assert::false($rules[ConsoleRename::class]['active']);
	[$code, , $err] = runApp($root, ['config', '--only', ConsoleReport::class]);
	Assert::same(3, $code);
	Assert::same("Error: Option `--only` names rule `ConsoleReport`, which cannot run here: no preset or layer of the configuration names its decisions.\n", $err);
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


test('a decision needing a newer PHP than the target is left out and said aloud when the project makes it', function () use ($root) {
	[$code, $out, $err] = runApp($root, ['check', 'src/b.php', '--set', 'upgrading.syntax.newWithoutWrapping=adopted']);
	Assert::same(0, $code);
	Assert::match('Warning: Decision `upgrading.syntax.newWithoutWrapping` needs PHP >=8.4 and the target is %a%; skipped.%A%', $err);
	Assert::match("%A%OK  1 file, up to the dress code\n", $out);
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


test('catalogue lists every decision, those the configuration makes marked, and writes it as data', function () use ($root) {
	[$code, $out] = runApp($root, ['catalogue']);
	Assert::same(0, $code);
	Assert::match("%A?%  file.finalLineEndings %s%%a%\n%A%* project.rename %s%%a%\n%A%\n* made by the configuration\n", $out);
	Assert::same(2, preg_match_all('~^\*~m', $out), 'a default nobody wrote is not made by the configuration');
	Assert::true(strpos($out, 'file.finalLineEndings') < strpos($out, 'spacing.call'), 'the sections stand in the order of the core');

	[$code, $out] = runApp($root, ['catalogue', '--format', 'json']);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true);
	Assert::same(DressCode\Config\Catalogue::Version, $data['version']);
	Assert::same('compact', $data['decisions']['spacing.call']['standards']['nette']);
	Assert::contains('no_spaces_after_function_name', $data['decisions']['spacing.call']['covers']);
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
		. "return new Config(\n"
		. "\tuse: ['psr12'],\n"
		. "\tdecisions: [\n"
		. "\t\t'multiline' => [\n"
		. "\t\t\t'trailingComma' => [\n"
		. "\t\t\t\t'array' => 'required',\n"
		. "\t\t\t\t'argument' => 'required',\n"
		. "\t\t\t\t'parameter' => 'keep',\n"
		. "\t\t\t\t'matchArm' => 'keep',\n"
		. "\t\t\t\t'closureUse' => 'keep',\n"
		. "\t\t\t\t'import' => 'keep',\n"
		. "\t\t\t\t'list' => 'keep',\n"
		. "\t\t\t],\n"
		. "\t\t],\n"
		. "\t\t'blankLines' => [\n"
		. "\t\t\t'betweenDeclarations' => 1,\n"
		. "\t\t\t'betweenMethods' => 1,\n"
		. "\t\t\t'betweenInterfaceMethods' => 1,\n"
		. "\t\t\t'beforeFirstMethod' => 0,\n"
		. "\t\t\t'afterLastMethod' => 0,\n"
		. "\t\t],\n"
		. "\t],\n"
		. ");\n",
		$out,
	);
	Assert::same(
		"\nRead 5 rules; set 12 decisions and 1 preset.\n"
		. "  `Squiz.WhiteSpace.FunctionSpacing` leaves the blank lines around classes alone, while `blankLines` sets them together with those around functions.\n"
		. "  No DressCode rule covers `Squiz.Nonsense.DoesNotExist`.\n",
		$err,
	);

	file_put_contents("$root/fixer.php", "<?php\nreturn new class {\n\tpublic function getRules(): array\n\t{\n\t\treturn ['cast_spaces' => ['space' => 'none']];\n\t}\n};\n");
	[$code, $out] = runApp($root, ['import', 'fixer.php']); // relative to the working directory of the run
	Assert::same(0, $code);
	Assert::contains("\t\t'spacing' => [\n\t\t\t'cast' => 'compact',\n\t\t],\n", $out);

	[$code, , $err] = runApp($root, ['import']);
	Assert::same(3, $code);
	Assert::match('Error: Missing required argument <file>.%A%', $err);
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
	[$code, , $err] = runApp($root, ['config', '--format', 'markdown']);
	Assert::same(3, $code);
	Assert::match("Error: Option --format: expects console or json, 'markdown' given.%A%", $err);
	[$code, , $err] = runApp($root, ['explain', '--format', 'json']);
	Assert::same(3, $code);
	Assert::match("Error: Option --format: expects console or markdown, 'json' given.%A%", $err);
	[$code, , $err] = runApp($root, ['check', '--set', 'project.rename={']);
	Assert::same(3, $code);
	Assert::match('Error: Option `--set` has an invalid value in `project.rename={`:%A%', $err);
	[$code, , $err] = runApp($root, ['check', '--set', 'project.rename=off']);
	Assert::same(3, $code);
	Assert::match('Error: %A%project.rename%A%', $err);
	[$code, , $err] = runApp($root, ['check', '--config', "$root/none.php"]);
	Assert::same(3, $code);
	Assert::match('Error: Configuration file `%a%` does not exist.%A%', $err);
	[$code, , $err] = runApp($root, ['check', 'none.php']);
	Assert::same(3, $code);
	Assert::same("Error: Path `none.php` does not exist.\n", $err);
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


test('without a configuration file neither check nor fix runs, unless a preset is named', function () {
	$dir = sys_get_temp_dir();
	foreach (['check', 'fix'] as $command) {
		[$code, , $err] = runApp($dir, [$command]);
		Assert::same(3, $code);
		Assert::match("Error: No `dresscode.neon` or `dresscode.php` found in `%a%` or above it, so there is no dress code to check against. Run `dresscode init`%A%.\nSee https://dresscode.run/cli#init\n", $err);
	}

	[$code, , $err] = runApp($dir, ['check', '--use', 'perCs']);
	Assert::same(3, $code);
	Assert::match('Error: No paths given and none configured.%A%', $err);
});


test('a file --use names is relative to the working directory, one the configuration uses to its root', function () {
	$dir = createTempDir('console-use');
	mkdir("$dir/config");
	file_put_contents("$dir/config/dresscode.php", "<?php\nreturn new DressCode\\Config(use: ['base.neon']);\n");
	file_put_contents("$dir/config/base.neon", "blankLines:\n\tbetweenMethods: 3\n");
	file_put_contents("$dir/style.neon", "blankLines:\n\tafterImports: 2\n");
	[$code, $out] = runApp($dir, ['config', '--config', "$dir/config/dresscode.php", '--use', 'style.neon']);
	Assert::same(0, $code);
	Assert::match("%A%\tafterImports: 2 %A%/console-use/style.neon\n\tbetweenMethods: 3 %A%/console-use/config/base.neon\n%A?%", $out);
});


test('a baseline is generated by the baseline command over what a fix leaves and baselines what it finds', function () {
	$root = createConsoleProject();
	file_put_contents("$root/baseline.php", "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['report' => 'forbidden']], paths: ['src'], baseline: 'baseline.neon');\n");
	// a fix would still rename in src/a.php and src/b.php does not parse, so what a fix leaves is not known yet
	file_put_contents("$root/src/b.php", "<?php\n\$x = ;\n");
	[$code, , $err] = runApp($root, ['baseline', '--config', "$root/baseline.php", '--set', 'project.rename=forbidden']);
	Assert::same(1, $code);
	Assert::same("Error: The baseline is generated over code a fix leaves alone: a fix would change 1 file, 1 file failed. Run `fix` first.\n  src/a.php\n  src/b.php\nSee https://dresscode.run/suppressing#baseline\n", $err);
	Assert::false(is_file("$root/baseline.neon"));
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	foreach (['--fix-risky', '--only', '--stdin', '--diff', '--max-warnings'] as $option) {
		[$code, , $err] = runApp($root, ['baseline', '--config', "$root/baseline.php", $option, 'x']);
		Assert::same(3, $code, $option);
		Assert::match("Error: %a%$option %a%\n%A%", $err);
	}

	// a baseline that cannot be read is replaced, and it is no obstacle to the migration of the comments
	file_put_contents("$root/baseline.neon", "a: [b\n");
	Assert::same(0, runApp($root, ['migrate-suppressions', '--config', "$root/baseline.php"])[0]);
	[$code, $out, $err] = runApp($root, ['baseline', '--config', "$root/baseline.php", '--format', 'json']);
	Assert::same(0, $code);
	Assert::same('', $out); // a machine-readable format keeps its stream to itself
	Assert::same("Baseline with 2 violations written to `baseline.neon`.\n", $err);
	[$code, $out] = runApp($root, ['baseline', '--config', "$root/baseline.php"]);
	Assert::same(0, $code);
	Assert::same("Baseline with 2 violations written to `baseline.neon`.\n", $out);
	Assert::match('%A%decision: project.report%A%', (string) file_get_contents("$root/baseline.neon"));

	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php"]);
	Assert::same(0, $code);
	Assert::match("%A%OK  2 violations in the baseline in 2 files\n", $out);
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php", '--set', 'project.rename=forbidden', '--format', 'json']);
	Assert::same(1, $code);
	Assert::match('%A%"baselined": 2%A%"warnings": []%A%', $out);

	file_put_contents("$root/src/a.php", "<?php\n");
	[$code, $out] = runApp($root, ['check', '--config', "$root/baseline.php"]);
	Assert::same(0, $code);
	Assert::match("%A%Warning: 1 entry of the baseline no longer matches a violation; regenerate it with `dresscode baseline`.\n\nOK  1 violation in the baseline in 2 files\n", $out);
	// a run narrowed to another rule says nothing about the entries of this one
	[, $out] = runApp($root, [
		'check',
		'--config', "$root/baseline.php",
		'--set', 'project.rename=forbidden',
		'--only', ConsoleRename::class,
		'--format', 'json',
	]);
	Assert::match('%A%"warnings": []%A%', $out);
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	// with no baseline named it lands beside the configuration, in its format
	file_put_contents("$root/report.php", "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['report' => 'forbidden']], paths: ['src']);\n");
	[$code, $out] = runApp($root, ['baseline', '--config', "$root/report.php"]);
	Assert::same(0, $code);
	Assert::match("Baseline with 2 violations written to `dresscode-baseline.php`.\nName it under `baseline` in the configuration to make it apply.\n", $out);
	Assert::match("%A%'decision' => 'project.report',%A%", (string) file_get_contents("$root/dresscode-baseline.php"));
});


test('workers give the same results as the in-process run', function () use ($root) {
	file_put_contents("$root/jobs.php", "<?php\nreturn new DressCode\\Config(paths: ['jobs'], decisions: ['file' => ['trailingWhitespace' => 'forbidden']]);\n");
	@mkdir("$root/jobs"); // @ directory may already exist
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
	Assert::match("%A%OK  3 files, all up to the dress code\n", $out);
});


test('a file a worker cannot write fails alone, the others are fixed', function () use ($root) {
	file_put_contents("$root/readonly.php", "<?php\nreturn new DressCode\\Config(paths: ['readonly'], decisions: ['file' => ['trailingWhitespace' => 'forbidden']]);\n");
	@mkdir("$root/readonly"); // @ directory may already exist
	file_put_contents("$root/readonly/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/readonly/b.php", "<?php\n\$b; \n");
	chmod("$root/readonly/a.php", 0o444);
	try {
		[$code, $out, $err] = runApp($root, ['fix', '--config', "$root/readonly.php", '--no-cache', '--jobs', '2']);
	} finally {
		chmod("$root/readonly/a.php", 0o666);
	}

	Assert::same('', $err);
	Assert::same(2, $code);
	Assert::match('%A%Cannot write file `readonly/a.php`.%A%', $out);
	Assert::same("<?php\n\$a; \n", file_get_contents("$root/readonly/a.php"));
	Assert::same("<?php\n\$b;\n", file_get_contents("$root/readonly/b.php"));
});


test('rules that do not fit are a configuration error with workers too', function () use ($root) {
	file_put_contents("$root/typo.php", <<<'PHP'
		<?php
		#[DressCode\RuleInfo(DressCode\Stage::Structure)]
		final class ConsoleTypo extends DressCode\NodeRule
		{
			public static function getDecisions(): array
			{
				return [new DressCode\Decision('project.typo', DressCode\Domain::state('forbidden'), 'typo')];
			}

			public function getVisitedNodes(): array
			{
				return ['Acme\Shop\NoSuchNode'];
			}
		}

		return new DressCode\Config(rules: [ConsoleTypo::class], decisions: ['project' => ['typo' => 'forbidden']], paths: ['src']);
		PHP);
	[$code, , $err] = runApp($root, ['check', '--config', "$root/typo.php", '--no-cache', '--jobs', '2']);
	Assert::same(3, $code);
	Assert::match('%A%Rule `ConsoleTypo` visits `Acme\Shop\NoSuchNode`, which is no class of a node or a token.%A%', $err);
});


test('a profile counts the same with workers as in the process', function () use ($root) {
	file_put_contents("$root/profile.php", "<?php\nreturn new DressCode\\Config(paths: ['profile'], decisions: ['builtin' => ['casing' => ['keyword' => 'lowercase']], 'file' => ['trailingWhitespace' => 'forbidden']]);\n");
	@mkdir("$root/profile"); // @ directory may already exist
	for ($i = 0; $i < 8; $i++) {
		file_put_contents("$root/profile/$i.php", "<?php\nIF (\$a) { \$b; }\t\n");
	}

	$counts = [];
	foreach (['1', '2'] as $jobs) {
		[$code, , $err] = runApp($root, ['check', '--config', "$root/profile.php", '--jobs', $jobs, '--profile', "$root/profile-$jobs.json"]);
		Assert::same('', $err);
		Assert::same(1, $code);
		$profile = json_decode(FileSystem::read("$root/profile-$jobs.json"), associative: true);
		Assert::same(8, $profile['files']);
		Assert::true($profile['rules'][DressCode\Rules\Literals\BuiltinCasingRule::class]['calls'] > 0);
		Assert::same(8, $profile['rules'][DressCode\Rules\Literals\BuiltinCasingRule::class]['mutating']);
		Assert::true($profile['phases']['passes']['time'] >= array_sum(array_column($profile['rules'], 'time')));
		$count = [
			array_map(fn($entry) => [$entry['calls'], $entry['mutating']], $profile['rules']),
			array_map(fn($entry) => $entry['calls'], $profile['claims']),
			array_map(fn($entry) => $entry['calls'], array_intersect_key($profile['phases'], ['parse' => 0, 'passes' => 0, 'print' => 0, 'gaps' => 0])),
		];
		array_walk($count, ksort(...)); // the tables are ordered by time, which differs between runs
		$counts[] = $count;
	}

	Assert::same($counts[0], $counts[1]);
	Assert::count(2 + 1, json_decode(FileSystem::read("$root/profile-2.json"), associative: true)['peakMemory']); // the parent and two workers

	// writing a baseline is a run of check too, whether it ends with one or with code a fix would change
	runApp($root, ['baseline', '--config', "$root/profile.php", '--profile', "$root/profile-baseline.json"]);
	Assert::same(8, json_decode(FileSystem::read("$root/profile-baseline.json"), associative: true)['files']);
});


test('a worker has the settings of the process the ini file alone would not give it', function () use ($root) {
	// the configuration turns the rule on by a setting, which a worker has only when it is handed over
	file_put_contents("$root/ini.php", "<?php\nreturn new DressCode\\Config(paths: ['ini'], decisions: ['file' => ['trailingWhitespace' => ini_get('user_agent') === 'dresscode-test' ? 'forbidden' : 'keep']]);\n");
	@mkdir("$root/ini"); // @ directory may already exist
	file_put_contents("$root/ini/a.php", "<?php\n\$a; \n");
	file_put_contents("$root/ini/b.php", "<?php\n\$b; \n");
	$previous = ini_set('user_agent', 'dresscode-test');
	try {
		[$code, $out, $err] = runApp($root, ['check', '--config', "$root/ini.php", '--no-cache', '--jobs', '2']);
	} finally {
		ini_set('user_agent', (string) $previous);
	}

	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  2 violations%A%', $out);
});


test('a baseline generated by workers is the one the single process writes, and it does not stand in the way of a fix', function () {
	$root = createConsoleProject();
	$a = "<?php\nbase64_decode(\$a);\n";
	$c = "<?php\nbase64_decode(\$a);\nbase64_decode(\$a);\n";
	mkdir("$root/jobs");
	file_put_contents("$root/jobs/a.php", $a);
	file_put_contents("$root/jobs/b.php", "<?php\n\$x;\n");
	file_put_contents("$root/jobs/c.php", $c);
	file_put_contents("$root/jobs.php", "<?php\nreturn new DressCode\\Config(paths: ['jobs'], baseline: 'jobs-baseline.neon', decisions: ['correctness' => ['strictComparisonArgument' => 'required']]);\n");
	$config = ['--config', "$root/jobs.php", '--no-cache'];

	// the risky fixes the configuration does not accept are what a fix leaves
	runApp($root, ['baseline', ...$config, '--jobs', '1']);
	$single = (string) file_get_contents("$root/jobs-baseline.neon");
	runApp($root, ['baseline', ...$config, '--jobs', '3']);
	Assert::same($single, (string) file_get_contents("$root/jobs-baseline.neon"));
	Assert::match('%A%decision: correctness.strictComparisonArgument%A%', $single);

	// what the baseline holds is not reported, and the fix the run does not allow is not made
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
		Assert::same("<?php\nbase64_decode(\$a, true);\n", (string) file_get_contents("$root/jobs/a.php"));
	}
});


test('migrate-suppressions rewrites phpcs comments to the dresscode form', function () {
	$root = createConsoleProject();
	file_put_contents("$root/src/s.php", "<?php\n// phpcs:ignoreFile\n/**\n * @phpcsSuppress Squiz.WhiteSpace.SuperfluousWhitespace\n */\nclass S\n{\n\t// phpcs:ignore Squiz.WhiteSpace.SuperfluousWhitespace, Generic.Metrics.CyclomaticComplexity -- why\n\tpublic \$a; // phpcs:disable Squiz.WhiteSpace.SuperfluousWhitespace\n\t// phpcs:enable\n}\n");
	[$code, $out, $err] = runApp($root, ['migrate-suppressions', 'src/s.php']);
	Assert::same('', $err);
	Assert::same(0, $code);
	Assert::same(
		"Migrated 5 suppression comments in 1 file.\n"
		. "Warning: The names DressCode does not know are kept as they are: `Generic.Metrics.CyclomaticComplexity`.\n"
		. "Note: A `dresscode:ignore` on a line of its own covers the whole statement below it, not just the next line; review the migrated ones.\n",
		$out,
	);
	Assert::same(
		"<?php\n// dresscode:ignoreFile\n/**\n * @phpcsSuppress file.trailingWhitespace\n */\nclass S\n{\n\t// dresscode:ignore file.trailingWhitespace, Generic.Metrics.CyclomaticComplexity -- why\n\tpublic \$a; // dresscode:disable file.trailingWhitespace\n\t// dresscode:enable\n}\n",
		file_get_contents("$root/src/s.php"),
	);
	[$code, $out] = runApp($root, ['migrate-suppressions', 'src/s.php']);
	Assert::same(0, $code);
	Assert::same("Migrated 0 suppression comments in 0 files.\n", $out);
});


test('migrate-suppressions narrows a directory holding the configured paths to them', function () {
	$root = createConsoleProject();
	mkdir("$root/other");
	file_put_contents("$root/other/s.php", "<?php\n// phpcs:ignoreFile\n");
	[, $out] = runApp($root, ['migrate-suppressions', '.']);
	Assert::same("Migrated 0 suppression comments in 0 files.\n", $out);
	Assert::same("<?php\n// phpcs:ignoreFile\n", file_get_contents("$root/other/s.php"));
	[, $out] = runApp("$root/other", ['migrate-suppressions', '.']);
	Assert::match('Migrated 1 %a% in 1 file.%A?%', $out);
	Assert::same("<?php\n// dresscode:ignoreFile\n", file_get_contents("$root/other/s.php"));
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


test('config writes every decision a layer set in the shape of the file, with the layer that set it', function () use ($root) {
	file_put_contents("$root/conf.neon", <<<'XX'
		use:
			- dresscode/psr12

		suppressionComments:
			"~intentionally ==~": [expressions.comparison.equality]

		qualification: keep

		upgrading:
			syntax:
				promotedProperties: keep

		file:
			lineLength:
				max: 100
				except: []
				overMax: forbidden

		overrides:
			- paths: [src/generated]
			  indentation: keep

		paths: [src]

		XX);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon"]);
	Assert::same(0, $code);
	Assert::match('%A%Use        dresscode/psr12%A%', $out);
	Assert::match('%A%Comments   silence decisions on their line%A%      ~intentionally ==~ %a%expressions.comparison.equality%A%', $out);
	Assert::match('%A%Style%a%4 spaces, the line ending each file mostly has, lines of up to 100 characters%A%', $out);
	Assert::match('%A%Decisions  %d% of %d% set by a layer, the others asking for nothing%A%', $out);
	Assert::match("%A%\nfile:\n%A%\tlineLength:\n%A?%\t\tmax: 100 %s%# the configuration\n%A?%", $out);
	Assert::match("%A%\nqualification:\n%A%\tglobal:\n%A?%\t\tfunction: keep %s%# the configuration\n%A?%", $out);
	Assert::match("%A%\tcall: compact %s%# dresscode/psr12\n%A?%", $out);
	Assert::match("%A%\tsyntax:\n\t\tpromotedProperties: keep %s%# the configuration\n%A?%", $out);

	// for one file it is what the run uses for that file
	[, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--file', 'src/generated/x.php']);
	Assert::match('%A%File       src%a%generated%a%x.php%A%', $out);
	Assert::match("%A%\nindentation:\n%A%\tunit: keep %s%# the override for src/generated\n%A?%", $out);

	// a preset alone, whatever the configuration is
	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--preset', 'psr12']);
	Assert::same(0, $code);
	Assert::match('%A%Config     none, using psr12%A%', $out);
	Assert::match("%A%\tlineLength:\n%A?%\t\tmax: 120 %s%# dresscode/psr12\n%A?%", $out);
	[, $out] = runApp($root, ['config', '--preset', 'psr12', '--set', 'file.lineLength.max=90']);
	Assert::match("%A%\tlineLength:\n%A?%\t\tmax: 90 %s%# the command line\n%A?%", $out);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--format', 'json']);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true);
	Assert::same(1, $data['version']);
	Assert::same(['psr12'], $data['use']);
	Assert::same(['php' => '8.4'], $data['targets']);
	Assert::same(4, $data['indent']);
	Assert::same(100, $data['lineLength']);
	Assert::same('uncertain', $data['nameResolution']);
	Assert::same(['~intentionally ==~' => ['expressions.comparison.equality']], $data['suppressionComments']);
	Assert::same([], $data['decisions']['file.lineLength.except']['value']);
	Assert::false($data['rules'][DressCode\Rules\Namespaces\GlobalNameQualificationRule::class]['active']);
	Assert::same(['reason' => 'turnedOff', 'message' => 'its decisions are `keep`'], $data['rules'][DressCode\Rules\Namespaces\GlobalNameQualificationRule::class]['inactive']);
	Assert::same('notMentioned', $data['rules'][StaticForClosureWithoutThisRule::class]['inactive']['reason']);
});


test('config names the targets of the packages and the plugins of the configuration', function () use ($root) {
	file_put_contents("$root/targets.neon", <<<'XX'
		use: [dresscode/psr12, ConsolePlugin]
		targets: {php: '8.4', acme/mailer: '3.3'}
		paths: [src]

		XX);

	[$code, $out, $err] = runApp($root, ['config', '--config', "$root/targets.neon"]);
	Assert::same('', $err);
	Assert::same(0, $code);
	Assert::match('%A%Use        ConsolePlugin, dresscode/psr12%A%', $out);
	Assert::match('%A%Targets    php 8.4, acme/mailer 3.3%A%', $out);

	[, $out] = runApp($root, ['config', '--config', "$root/targets.neon", '--format', 'json']);
	$data = json_decode($out, associative: true);
	Assert::same(['php' => '8.4', 'acme/mailer' => '3.3'], $data['targets']);
	Assert::same(['ConsolePlugin', 'psr12'], $data['use']);
});


test('overrides: another part of the tree gets other rules, and the run, the cache and the workers agree', function () use ($root) {
	@mkdir("$root/lib"); // @ directory may already exist
	@mkdir("$root/legacy"); // @ directory may already exist
	foreach (['lib/a.php', 'legacy/b.php', 'legacy/e.php', 'legacy/deep/c.php'] as $path) {
		@mkdir(dirname("$root/$path"), recursive: true); // @ directory may already exist
		file_put_contents("$root/$path", "<?php\n\$a;\n");
	}

	file_put_contents("$root/overrides.neon", <<<'XX'
		file:
			trailingWhitespace: forbidden

		overrides:
			- paths: [legacy]
			  file: {trailingWhitespace: keep, finalLineEndings: 1}
			- paths: [legacy/deep]
			  file: {finalLineEndings: keep}

		paths: [lib, legacy]
		cacheDir: cache

		XX);
	$config = ['--config', "$root/overrides.neon", '--no-cache'];

	// what the configuration comes to for a file is what the overrides it matches say, in the order written
	$names = function (string $file, string $cwd = '') use ($root): array {
		[, $out] = runApp($root . $cwd, ['config', '--config', "$root/overrides.neon", '--file', $file, '--format', 'json']);
		$data = json_decode($out, associative: true);
		return array_map(ruleSlug(...), array_keys(array_filter($data['rules'], fn(array $rule) => $rule['active'])));
	};
	Assert::same(['noTrailingWhitespace'], $names('lib/a.php'));
	Assert::same(['finalLineEndings'], $names('legacy/b.php')); // the override turned the first rule off
	Assert::same([], $names('legacy/deep/c.php')); // and the second override turned the other one off
	Assert::same(['finalLineEndings'], $names('b.php', '/legacy')); // relative to the working directory, as check takes it

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
		"<?php\n\$a;\n",   // lib: noTrailingWhitespace ran
		"<?php\n\$a; \n",  // legacy: it did not
		"<?php\n\$a;\n",   // legacy: finalLineEndings did
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
	@mkdir("$root/only"); // @ directory may already exist
	foreach (['a', 'b', 'c', 'd'] as $name) {
		file_put_contents("$root/only/$name.php", "<?php\n\$a; \n\$b;"); // trailing whitespace, no newline at the end
	}

	file_put_contents("$root/only.neon", <<<'XX'
		file:
			trailingWhitespace: forbidden
			finalLineEndings: 1

		paths: [only]
		cacheDir: cache

		XX);
	$config = ['--config', "$root/only.neon"];

	// check reports what the named rule reports and nothing else, with workers as without them
	[$code, $expected] = runApp($root, ['check', ...$config, '--only', 'file.trailingWhitespace', '--no-cache']);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  4 violations, a fix leaves none in 4 files%A%', $expected);
	Assert::notContains('file.finalLineEndings', $expected);
	[, $out] = runApp($root, ['check', ...$config, '--only', 'file.trailingWhitespace', '--no-cache', '--jobs', '2']);
	Assert::same($expected, $out);

	// fix changes what that rule reports and leaves the rest alone
	[$code] = runApp($root, ['fix', ...$config, '--only', 'file.trailingWhitespace', '--jobs', '2']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$a;\n\$b;", file_get_contents("$root/only/a.php"));

	// what the narrowed run remembered as clean is not clean for the whole configuration
	Assert::same(0, runApp($root, ['check', ...$config, '--only', 'file.trailingWhitespace'])[0]);
	[$code, $out] = runApp($root, ['check', ...$config]);
	Assert::same(1, $code);
	Assert::match('%A%FOUND  4 violations, a fix leaves none in 4 files%A%', $out);
	Assert::contains('file.finalLineEndings', $out);

	// config says of every other decision that the run leaves it out
	[, $out] = runApp($root, ['config', ...$config, '--only', 'file.trailingWhitespace']);
	Assert::match("%A%\tfinalLineEndings: 1 %s%# the configuration, outside --only\n%A?%", $out);
});


test('exit codes: violations, warnings, the warning threshold, a syntax error and a failing rule', function () {
	$root = createConsoleProject();
	$config = "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden', 'report' => 'forbidden']], paths: ['src']";
	$write = fn(string $tail) => file_put_contents("$root/exit.php", "$config$tail);\n");
	/** @param list<string> $args */
	$run = fn(array $args = []) => runApp($root, array_values(['check', '--config', "$root/exit.php", '--no-cache', ...$args]))[0];

	$write('');
	Assert::same(1, $run()); // violations of both rules
	Assert::same(1, runApp($root, ['fix', '--config', "$root/exit.php", '--no-cache'])[0]); // ConsoleReport fixes nothing
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");

	// the same violations as warnings: reported, counted, and the exit code stays clean
	$write(', warnOnly: [ConsoleRename::class, ConsoleReport::class]');
	[$code, $out] = runApp($root, ['check', '--config', "$root/exit.php", '--no-cache']);
	Assert::same(0, $code);
	Assert::match('%A%  warning  2:1  Rename $a.  project.rename%A%FOUND  3 warnings in 2 files%A%', $out);
	Assert::same(0, $run(['--max-warnings', '3']));
	Assert::same(1, $run(['--max-warnings', '2']));
	// and so it is over stdin
	/** @param list<string> $args */
	$stdin = fn(array $args) => runApp($root, array_values(['check', '--config', "$root/exit.php", '--no-cache', ...$args, '--stdin', 'src/b.php']), "<?php\n\$x;\n")[0];
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
	[$code, $out, $err] = runApp($root, ['check', '--config', "$root/failing.php", '--no-cache']);
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

	file_put_contents("$root/invalid.php", "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden']], paths: ['src'], baseline: 1);\n");
	Assert::same(3, runApp($root, ['check', '--config', "$root/invalid.php", '--no-cache'])[0]);
	Assert::same(3, runApp($root, ['check', '--nope'])[0]);
	[$code, , $err] = runApp($root, ['config', '--preset', '']);
	Assert::same(3, $code);
	Assert::match("Error: Option `--preset`: `use` names a preset or the path of a file, an empty string given.\n%A%", $err);
	Assert::same(3, runApp($root, ['baseline', '--fix-risky'])[0]);
	Assert::same(3, runApp($root, ['baseline', '--only', 'project.rename'])[0]);
	foreach ([['--jobs', 'abc'], ['--jobs', '0'], ['--max-warnings', 'xyz'], ['--max-warnings', '-1']] as [$option, $value]) {
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

	// refused: reported, counted apart with the way to decide it, not fixed, and a change of what the code does
	// is an error the exit code says
	$write('');
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache']);
	Assert::same(1, $code);
	Assert::contains('1 risky fix changes what the code does: decide it with `fix --ask-risky`, or accept its decision in `fixRisky`. See https://dresscode.run/configuration#risky-fixes', $out);
	Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));

	// the flag of the run allows it
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--fix-risky']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));
	Assert::notContains('risky fix', $out);

	// a review asks in a terminal and about each fix, so neither a pipe nor a consent given beforehand goes with it
	[$code, , $err] = runApp($root, ['fix', '--config', $config, '--no-cache', '--ask-risky']);
	Assert::same(3, $code);
	Assert::contains('`--ask-risky` asks in an interactive terminal; without one, allow the risky fixes with `--fix-risky=<name>`.', $err);
	[, , $err] = runApp($root, ['fix', '--config', $config, '--no-cache', '--ask-risky', '--fix-risky']);
	Assert::contains('`--ask-risky` asks about the risky fixes one by one, so it goes with neither `--fix-risky` nor `--stdin`.', $err);

	// at a terminal it asks after the fix, and the exit code is that of the files as the answers left them
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--ask-risky'], "n\n", interactive: true);
	Assert::same(1, $code);
	Assert::match("%A%Apply this risky fix? [y,n,a,q] \nREVIEWED  0 risky fixes made, 1 left\n", $out);
	Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--ask-risky'], "y\n", interactive: true);
	Assert::same(0, $code);
	Assert::match("%A%-\$r;\n+\$s;\nApply this risky fix? [y,n,a,q] \nREVIEWED  1 risky fix made, none left\n", $out);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--ask-risky'], interactive: true);
	Assert::same(0, $code);
	Assert::match("%A%\nREVIEWED  no risky fixes to ask about\n", $out);
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");

	// or only for the decisions, rules and presets it names, with workers as without them
	foreach (['1', '2'] as $jobs) {
		file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
		Assert::same(1, runApp($root, ['fix', '--config', $config, '--no-cache', '--jobs', $jobs, '--fix-risky=correctness.strictComparisonArgument'])[0]);
		Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));
		Assert::same(0, runApp($root, ['fix', '--config', $config, '--no-cache', '--jobs', $jobs, '--fix-risky=project.riskyRename'])[0]);
		Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));
	}

	// and so does the configuration, for the rules it names
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write(', fixRisky: [ConsoleRiskyRename::class]');
	Assert::same(0, runApp($root, ['fix', '--config', $config, '--no-cache'])[0]);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));

	// the JSON says of every violation why it was risky and how many are waiting
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$write('');
	[, $out] = runApp($root, ['check', '--config', $config, '--no-cache', '--format', 'json']);
	Assert::contains('"risk": "behaviorChanges"', $out);
	Assert::contains('"refused": 1', $out);

	// a comment silences it like any other violation
	file_put_contents("$root/src/r.php", "<?php\n\$r; // dresscode:ignore project.riskyRename\n");
	Assert::same(0, runApp($root, ['check', '--config', $config, '--no-cache'])[0]);
});


test('a violation the rule has no fix for is no fix waiting, with the consent or without it', function () {
	$root = createTempDir('unfixable');
	mkdir("$root/src");
	$config = "$root/strictComparisonArgumentRequired.php";
	// an explicit false is only reported, so neither the consent nor its absence has a fix to make
	foreach ([
		['in_array(1, [1], false)', '', 1, 0],
		['in_array(1, [1], false)', ", fixRisky: ['correctness.strictComparisonArgument']", 1, 0],
		['in_array(1, [1])', '', 1, 1],
		['in_array(1, [1])', ", fixRisky: ['correctness.strictComparisonArgument']", 0, 0],
	] as [$call, $tail, $remaining, $deferred]) {
		file_put_contents("$root/src/a.php", "<?php\n$call;\n");
		file_put_contents($config, "<?php\nreturn new DressCode\\Config(paths: ['src']$tail, decisions: ['correctness' => ['strictComparisonArgument' => 'required']]);\n");

		[, $out] = runApp($root, ['check', '--config', $config, '--no-cache', '--format', 'json']);
		$summary = json_decode($out, associative: true)['summary'];
		Assert::same([$remaining, $deferred], [$summary['remaining'], $summary['refused']], "$call$tail");

		[, $out] = runApp($root, ['check', '--config', $config, '--no-cache']);
		if ($deferred) {
			Assert::contains('1 risky fix waits, the type is unknown', $out);
		} else {
			Assert::notContains('risky fix', $out);
		}
	}
});


test('a risky fix only the types could decide is a warning until it is made, and the summary says how to decide it', function () {
	$root = createTempDir('type-unknown');
	mkdir("$root/src");
	file_put_contents("$root/src/a.php", "<?php\nin_array(\$a, \$b);\n");
	$config = "$root/strictComparisonArgumentRequired.php";
	file_put_contents($config, "<?php\nreturn new DressCode\\Config(paths: ['src'], decisions: ['correctness' => ['strictComparisonArgument' => 'required']]);\n");

	[$code, $out] = runApp($root, ['check', '--config', $config, '--no-cache']);
	Assert::same(0, $code);
	Assert::match('%A%  risky  2:1  The `in_array()` call must pass `$strict = true`%A%', $out);
	Assert::match('%A%1 risky fix waits, the type is unknown: set `typeAnalysis: phpstan`. See https://dresscode.run/types#enable%A%', $out);
	Assert::same(1, runApp($root, ['check', '--config', $config, '--no-cache', '--max-warnings', '0'])[0]);
});


test('config says of a decision with risky fixes whether the project accepts them, and a name that does nothing is a warning', function () use ($root) {
	$config = "$root/risky-config.php";
	file_put_contents($config, "<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['riskyRename' => 'forbidden'], 'correctness' => ['strictComparisonArgument' => 'required']],"
		. " overrides: [new DressCode\\Override(['src'], new DressCode\\Profile(decisions: ['file' => ['strictTypes' => ['declaration' => 'required']]])), new DressCode\\Override(['legacy'], new DressCode\\Profile(decisions: ['file' => ['lineEnding' => 'LF']]))], fixRisky: [ConsoleRiskyRename::class, 'file.strictTypes.declaration', 'classes.markedInternal.class'], paths: ['src']);\n");

	[$code, $out] = runApp($root, ['config', '--config', $config]);
	Assert::same(0, $code);
	Assert::match("%A%\triskyRename: forbidden %s%# the configuration, risky fixes accepted\n%A?%", $out);
	Assert::match("%A%\tstrictComparisonArgument: required %s%# the configuration\n%A?%", $out);
	Assert::match("%A%\tstrictTypes:\n\t\tdeclaration: keep %s%# no layer, risky fixes accepted\n%A?%", $out);
	Assert::match("%A%\tmarkedInternal:\n\t\tclass: keep %s%# no layer, risky fixes accepted\n%A?%", $out);

	[, $out] = runApp($root, ['config', '--config', $config, '--format', 'json']);
	$rules = json_decode($out, associative: true)['rules'];
	Assert::same('onlyOverride', $rules[DressCode\Rules\Files\StrictTypesRequiredRule::class]['inactive']['reason']);
	Assert::same('notMentioned', $rules[FinalForInternalClassRule::class]['inactive']['reason']);
	Assert::true($rules[ConsoleRiskyRename::class]['fixRisky']);
	Assert::false($rules[DressCode\Rules\Functions\StrictComparisonArgumentRequiredRule::class]['fixRisky']);

	// so it is for a file another override matches
	[, $out] = runApp($root, ['config', '--config', $config, '--file', 'legacy/a.php', '--format', 'json']);
	$rules = json_decode($out, associative: true)['rules'];
	Assert::same('onlyOverride', $rules[DressCode\Rules\Files\StrictTypesRequiredRule::class]['inactive']['reason']);

	// a rule an override turns on runs somewhere, one that nothing turns on makes the entry a line that does nothing
	[, , $err] = runApp($root, ['check', '--config', $config, '--no-cache']);
	Assert::contains('Decision `classes.markedInternal.class` is named in `fixRisky` but runs nowhere; the entry does nothing.', $err);
	Assert::notContains('file.strictTypes.declaration', $err);
});
