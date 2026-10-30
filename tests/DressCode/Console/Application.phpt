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
	file_put_contents(
		"$root/dresscode.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden']], paths: ['src'], targets: ['php' => '8.3']);\n",
	);
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n");
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	return $root;
}


$root = createConsoleProject();


/**
 * @param  list<string>  $args
 * @return array{int, string, string}
 * @phpstan-impure
 */
function runApp(string $root, array $args, string $stdin = '', bool $xdebug = false, bool $interactive = false): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$in = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	fwrite($in, $stdin);
	rewind($in);
	$runs = in_array($args[0] ?? null, ['check', 'fix', 'baseline'], strict: true) || in_array('--today', $args, strict: true);
	if ($runs && !in_array('--jobs', $args, strict: true)) {
		$args = [...$args, '--jobs', '1']; // the rules of this file do not exist in a worker process
	}

	$code = new Application(
		$out,
		$err,
		$in,
		$root,
		script: __DIR__ . '/../../../bin/dresscode',
		xdebug: $xdebug,
		interactive: $interactive,
	)->run(
		[
			'dresscode',
			...$args,
		],
	);
	rewind($out);
	rewind($err);
	// how long a run took is up to the machine, which a busy one makes long enough to be said
	$clean = fn($stream) => (string) preg_replace('~, \d+\.\d s$~m', '', (string) stream_get_contents($stream));
	return [$code, $clean($out), $clean($err)];
}


test('help and version', function () use ($root) {
	[$code, $out, $err] = runApp($root, []);
	Assert::same(3, $code);
	Assert::same('', $out);
	Assert::match("Error: Missing command.\n\nUsage: dresscode [options] <command>\n\nA dress code for PHP: %A%", $err);
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
	Assert::same(
		"Error: Option `--only` names rule `ConsoleReport`, which cannot run here: no preset or layer of the configuration names its decisions.\n",
		$err,
	);
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
	[$code, $out, $err] = runApp($root, ['check', 'src/b.php', '--set', 'classes.instantiationWrapping=forbidden']);
	Assert::same(0, $code);
	Assert::match('Warning: Decision `classes.instantiationWrapping` needs PHP >=8.4 and the target is %a%; skipped.%A%', $err);
	Assert::match("%A%OK  1 file, up to the dress code\n", $out);
});


test('stdin: check reports, fix writes the result to stdout', function () use ($root) {
	[$code, $out] = runApp($root, ['check', '--stdin', 'src/x.php'], "<?php\n\$a;\n");
	Assert::same(1, $code);
	Assert::match("%a%x.php\n  error  2:1  Rename \$a.  project.rename\n\nFOUND  1 violation, a fix leaves none in 1 file\n", $out);
	[$code, $out, $err] = runApp($root, ['fix', '--stdin', 'src/x.php'], "<?php\n\$a;\n");
	Assert::same(0, $code);
	Assert::same("<?php\n\$b;\n", $out);
	Assert::same('', $err);

	// a path the configured ones do not hold is checked, unless the caller asks to skip it
	Assert::same(1, runApp($root, ['check', '--stdin', 'lib/x.php', '-f', 'lines'], "<?php\n\$a;\n")[0]);
	Assert::same([0, '', ''], runApp($root, ['check', '--stdin', 'lib/x.php', '-f', 'lines', '--skip-excluded'], "<?php\n\$a;\n"));
});


test('the format for an agent names the file as it was given and offers the comment the configuration of the file accepts', function () {
	$root = createConsoleProject();
	file_put_contents(
		"$root/dresscode.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden', 'riskyRename' => 'forbidden']], paths: ['src'], targets: ['php' => '8.3'], suppressionComments: ['~^// keep~' => ['decisions' => 'project.riskyRename', 'example' => '// keep: <why>']]);\n",
	);
	file_put_contents("$root/src/a.php", "<?php\n\$a;\n\$r;\n");

	// a warning of the configuration is not printed, the agent can do nothing about it
	[$code, $out, $err] = runApp($root, ['fix', './src/a.php', '-f', 'agent', '--set', 'classes.instantiationWrapping=forbidden']);
	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::match(<<<'XX'
		DressCode changed `./src/a.php`; the changes below are already in the file:

		```diff
		@@ -1,3 +1,3 @@
		 <?php
		-$a;
		+$b;
		 $r;
		```

		Left in `./src/a.php`, at the lines of the file as it is now:
		- line 3: rename $r (`project.riskyRename`). Not fixed, because %a%; where the current code is intended and you know why, keep it with a comment on its line, `// keep: <why>`, giving the real reason for `<why>`; otherwise tell the user.
		%A?%
		XX, $out);

	// a clean file says nothing
	Assert::same([0, '', ''], runApp($root, ['check', 'src/b.php', '-f', 'agent']));
});


test('--lines lists what stands on the lines of the file as it was read, counts the rest and fixes the whole file', function () {
	$root = createConsoleProject();
	$code = "<?php\nuse Acme\\Unused;\n\$x;\n\$a;\n";
	$fix = fn(string ...$lines) => runApp($root, [
		'fix', 'src/c.php', '-f', 'agent', '--set', 'imports.unused=forbidden', '--set', 'project.report=forbidden',
		...array_merge(...array_map(fn($range) => ['--lines', $range], $lines)),
	]);

	// the removed import moved the lines up: line 4 as read is line 3 of the fixed text
	file_put_contents("$root/src/c.php", $code);
	[$exit, $out] = $fix('4');
	Assert::same(1, $exit);
	Assert::same("<?php\n\$x;\n\$b;\n", file_get_contents("$root/src/c.php"));
	Assert::match(<<<'XX'
		DressCode changed `src/c.php`; the changes below are already in the file:

		- removed the import of `Acme\Unused`: %a%

		```diff
		%A%
		```

		Left in `src/c.php`, at the lines of the file as it is now:
		- line 3: seen (`project.report`).

		1 further violation is outside the edited lines; it is not listed here.
		%A?%
		XX, $out);

	// what stands outside does not decide the exit code, and the rewrite is reported wherever it is
	file_put_contents("$root/src/c.php", $code);
	[$exit, $out] = $fix('2');
	Assert::same(0, $exit);
	Assert::contains('DressCode changed `src/c.php`', $out);
	Assert::contains('2 violations in `src/c.php` are outside the edited lines; they are not listed here.', $out);
	Assert::notContains('Left in', $out);

	// ranges join, and one past the end of the file is its last line
	file_put_contents("$root/src/c.php", $code);
	Assert::notContains('outside the edited lines', $fix('3', '9')[1]);

	// a check reports by the same lines, and the cache keeps what the whole file holds
	file_put_contents("$root/src/c.php", $code);
	[$exit, $out] = runApp($root, ['check', 'src/c.php', '-f', 'agent', '--set', 'project.report=forbidden', '--lines', '1']);
	Assert::same(0, $exit);
	Assert::match("3 violations in `src/c.php` are outside the edited lines; they are not listed here.\n%A?%", $out);
	Assert::same(1, runApp($root, ['check', 'src/c.php', '-f', 'agent', '--set', 'project.report=forbidden'])[0]);

	// a syntax error is never narrowed away
	file_put_contents("$root/src/d.php", "<?php\n\$a = ;\n");
	[$exit, $out] = runApp($root, ['check', 'src/d.php', '-f', 'agent', '--lines', '1']);
	Assert::same(1, $exit);
	Assert::contains('has a syntax error on line 2', $out);
});


test('--before leaves what the file broke before the edit as the file writes it, and fixes the rest in the whole file', function () {
	$root = createConsoleProject();
	file_put_contents("$root/before.php", "<?php\nuse Acme\\Unused;\nnew Unused;\n\$a;\n");
	file_put_contents("$root/src/c.php", "<?php\nuse Acme\\Unused;\n\$a;\n\$a;\n");
	$args = ['fix', 'src/c.php', '-f', 'agent', '--set', 'imports.unused=forbidden', '--before', 'before.php', '--lines', '4'];

	// the import the edit left unused goes though it stands outside the lines, the renaming the file never kept stays
	[$exit, $out] = runApp($root, $args);
	Assert::same(0, $exit);
	Assert::same("<?php\n\$a;\n\$a;\n", file_get_contents("$root/src/c.php"));
	Assert::contains('removed the import of `Acme\Unused`', $out);
	Assert::contains(
		'Before the edit `src/c.php` did not follow `project.rename`, so DressCode leaves it as the file writes it; write the code there in the style of the rest of the file.',
		$out,
	);

	Assert::same(3, runApp($root, [...$args, '-f', 'json'])[0]);
	Assert::same(3, runApp($root, ['fix', 'src/c.php', '-f', 'agent', '--before', 'missing.php'])[0]);
});


test('--lines takes the lines of one file in the agent format, anything else is exit code 3', function () use ($root) {
	foreach (['0', '-2', '5-2', 'x', '2-'] as $range) {
		[$code, , $err] = runApp($root, ['check', 'src/a.php', '-f', 'agent', '--lines', $range]);
		Assert::same(3, $code);
		Assert::match("Error: Option `--lines`: expects a line or a range of lines such as `20-21`, `$range` given.%A%", $err);
	}

	foreach ([
		[['check', 'src/a.php', '--lines', '2'], '`--lines` narrows what the `agent` format reports, %a%'],
		[['check', 'src/a.php', '-f', 'agent', '--lines', '2', '--strict-rules'], '`--lines` names the lines of the file %a%'],
		[['check', 'src', '-f', 'agent', '--lines', '2'], '`--lines` names the lines of one file, but 2 files are to be run.'],
		[['fix', '--stdin', 'src/a.php', '--lines', '2'], '`fix --stdin` writes the fixed code alone, so it takes no `--lines`;%a%'],
	] as [$args, $error]) {
		[$code, , $err] = runApp($root, $args);
		Assert::same(3, $code);
		Assert::match("Error: $error%A%", $err);
	}
});


test('upgrade --today rewrites the code in no style without a configuration, and with one in the style its code keeps', function () {
	$code = "<?php\n\ndeclare(strict_types=1);\n\n\$h = curl_init();\ncurl_close(\$h);\n\$s = \"x\";\n";
	$upgraded = "<?php\n\ndeclare(strict_types=1);\n\n\$h = curl_init();\n\$s = \"x\";\n";

	// without a configuration the whole directory, by the sets alone; outside the repository, whose configuration it would find
	$root = sys_get_temp_dir() . '/dresscode-upgrade-' . getmypid();
	FileSystem::delete($root);
	FileSystem::createDir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": ">=8.2"}}');
	file_put_contents("$root/src/a.php", $code);
	[$exit, $out] = runApp($root, ['upgrade', '--today', '--no-color']);
	Assert::same(0, $exit);
	Assert::same($upgraded, file_get_contents("$root/src/a.php"));
	Assert::match(
		"%A%Config     none, using deprecations\n%A%Upgrading  PHP alone, no installed library has upgrading data\n%A%Run `dresscode upgrade --today` again: %a%\n",
		$out,
	);
	FileSystem::delete($root);

	// the code writes double quotes, so the quotes of the standard are left out, in the workers too
	$root = createTempDir('upgrade-configured');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": ">=8.2"}}');
	file_put_contents("$root/dresscode.neon", "use: [nette]\npaths: [src]\n");
	foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $name) {
		file_put_contents("$root/src/$name.php", $code);
	}

	[$exit] = runApp($root, ['upgrade', '--today', '--no-color', '--jobs', '2']);
	Assert::same(0, $exit);
	Assert::same($upgraded, file_get_contents("$root/src/a.php"));
	Assert::same($upgraded, file_get_contents("$root/src/h.php"));
	Assert::same(1, runApp($root, ['check', '--no-color'])[0]);
});


test('upgrade --today keeps what the project keeps, in an override too, the sets lying beneath its decisions', function () {
	$code = "<?php\n\ndeclare(strict_types=1);\n\n\$h = curl_init();\ncurl_close(\$h);\n";
	$root = createTempDir('upgrade-kept');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": ">=8.2"}}');
	file_put_contents(
		"$root/dresscode.neon",
		"paths: [src]\noverrides:\n\t-\n\t\tpaths: [src/kept.php]\n\t\tupgrading: {api: {replacedCalls: {'curl_close(\$handle)': keep}}}\n",
	);
	file_put_contents("$root/src/kept.php", $code);
	file_put_contents("$root/src/upgraded.php", $code);

	[$exit, , $err] = runApp($root, ['upgrade', '--today', '--no-color']);
	Assert::same(0, $exit);
	Assert::same('', $err);
	Assert::same($code, file_get_contents("$root/src/kept.php"));
	Assert::same("<?php\n\ndeclare(strict_types=1);\n\n\$h = curl_init();\n", file_get_contents("$root/src/upgraded.php"));
});


test('upgrade --today says which packages are installed newer than required, and whose data are only reported', function () {
	$root = createTempDir('upgrade-versions');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": ">=8.2", "acme/lib": "^3.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\n");
	file_put_contents("$root/src/a.php", "<?php\n");
	FileSystem::write(
		"$root/vendor/acme/tool/upgrading/tool.neon",
		"package: acme/tool\n\nsince 1.0:\n\tforbiddenFunctions:\n\t\tacme_old: 'use `acme_new()`'\n",
	);
	FileSystem::write(
		"$root/vendor/composer/installed.json",
		json_encode(['packages' => [
			['name' => 'acme/lib', 'version' => '3.2.0', 'version_normalized' => '3.2.0.0', 'install-path' => '../acme/lib'],
			[
				'name' => 'acme/tool',
				'version' => '1.0.0',
				'version_normalized' => '1.0.0.0',
				'install-path' => '../acme/tool',
				'extra' => ['dresscode' => ['upgrading-dir' => 'upgrading']],
			],
		]], JSON_THROW_ON_ERROR),
	);

	[, $out] = runApp($root, ['upgrade', '--today', '--no-color']);
	Assert::match(
		"%A%Upgrading  acme/tool\nLowest     acme/lib 3.2 installed, 3.0 required; `composer update --prefer-lowest` %a%\nReported   acme/tool: not required with a lower bound, %a%\n%A%",
		$out,
	);
});


test('upgrade --today installs the upgrading data a library of the project needs only when asked, or with --yes', function () {
	$root = createTempDir('upgrade-data');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": ">=8.2", "nette/utils": "^4.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\ncacheDir: cache\n");
	file_put_contents("$root/src/a.php", "<?php\n");
	// what Packagist said of the packages of upgrading data, remembered, so that the test asks it nothing
	FileSystem::write("$root/cache/data-packages.json", '{"dresscode/upgrade-nette": ["nette/", "latte/latte"]}');
	FileSystem::write(
		"$root/vendor/composer/installed.json",
		'{"packages": [{"name": "nette/utils", "version": "4.0.0", "version_normalized": "4.0.0.0", "install-path": "../nette/utils"}]}',
	);

	// outside a terminal it says how to install the data, and changes nothing
	[$exit, , $err] = runApp($root, ['upgrade', '--today', '--no-color']);
	Assert::same(3, $exit);
	Assert::match(
		"Error: The libraries of the project have upgrading data in `dresscode/upgrade-nette`, which DressCode does not have; install it with `composer require --working-dir=%a% dresscode/upgrade-nette`, or let `dresscode upgrade --today --yes` do it.\n%A?%",
		$err,
	);

	// in a terminal it asks, and declined it upgrades without the data
	[$exit, $out] = runApp($root, ['upgrade', '--today', '--no-color'], stdin: "n\n", interactive: true);
	Assert::same(0, $exit);
	Assert::match('%A%Install it beside DressCode in `%a%`?%A%', $out);
});


test('upgrade --today runs the rules the maps of a package of upgrading data turn on, check only where use names it', function () {
	require_once __DIR__ . '/fixtures/upgrade-plugin.php';
	$root = createTempDir('upgrade-plugin');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": ">=8.2", "acme/upgrade": "^1.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\n");
	file_put_contents("$root/src/a.php", "<?php\n\$x = 1;\n");
	FileSystem::write(
		"$root/vendor/acme/upgrade/upgrading/upgrading.neon",
		"package: acme/upgrade\n\nsince 1.0:\n\tacmeUpgrade.variables:\n\t\told: new\n",
	);
	FileSystem::write(
		"$root/vendor/composer/installed.json",
		json_encode(['packages' => [[
			'name' => 'acme/upgrade',
			'version' => '1.0.0',
			'version_normalized' => '1.0.0.0',
			'install-path' => '../acme/upgrade',
			'extra' => ['dresscode' => ['plugin' => 'UpgradePlugin', 'upgrading-dir' => 'upgrading']],
		]]], JSON_THROW_ON_ERROR),
	);

	// one warning for the plugin, none for the map of its rule
	Assert::same(
		"Warning: Package `acme/upgrade` brings rules that run only where `use` names it, and in `dresscode upgrade`; skipped, with what its upgrading files set for them.\n",
		runApp($root, ['check', '--no-color'])[2],
	);
	foreach ([[], ['--only', 'acme/upgrade']] as $only) {
		[, $out, $err] = runApp($root, ['upgrade', '--today', '--no-color', ...$only]);
		Assert::same('', $err);
		Assert::contains('A variable of the upgrade.', $out);
	}
});


test('upgrade without --today measures what an upgrade costs, topic by topic, and changes nothing', function () {
	// outside the repository, whose configuration it would find
	$root = sys_get_temp_dir() . '/dresscode-offer-' . getmypid();
	FileSystem::delete($root);
	FileSystem::createDir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
	$code = "<?php\n\nnamespace App;\n\nfunction run(\$handle, int \$count = null): void\n{\n\tcurl_close(\$handle);\n\tstrftime('%Y');\n}\n";
	file_put_contents("$root/src/Code.php", $code);

	[$exit, $out, $err] = runApp($root, ['upgrade', 'php', '8.4', '--no-types', '--offline', '--no-color']);
	Assert::same('', $err);
	Assert::same(0, $exit);
	Assert::match(<<<'XX'
		DRESS|CODE %a%
		Config     none, the scope `init` would write: src
		Upgrade    php 8.4 over 1 file, the names resolved for certain as measured, without the types of the code

		Must change, 3 topics in 3 places
		  php                             1 place in 1 file       after the raise, 1 for you
		  php                             1 place in 1 file       works today, automatic
		  upgrading.php.implicitNullable  1 place in 1 file       works today, automatic

		Today      2 places of `deprecations` are rewritten on the versions allowed now by `dresscode upgrade --today`
		Data       php up to %d%.%d%
		XX, $out);
	Assert::same($code, file_get_contents("$root/src/Code.php"));

	[$exit, $out] = runApp($root, ['upgrade', 'php', '8.4', '--no-types', '--offline', '--format', 'json']);
	Assert::same(0, $exit);
	// workers measure what the process alone does
	Assert::same($out, runApp($root, ['upgrade', 'php', '8.4', '--no-types', '--offline', '--format', 'json', '--jobs', '2'])[1]);
	$offer = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same([['package' => 'php', 'version' => '8.4']], $offer['targets']);
	Assert::same(
		['php jump', 'php bridge', 'upgrading.php.implicitNullable bridge'],
		array_map(fn(array $topic) => "$topic[name] $topic[phase]", $offer['topics']),
	);

	// a run of the plan keeps the style of the configuration, which the project does not have
	[$exit, , $err] = runApp($root, ['upgrade', 'php', '8.4', '--run', '--no-types', '--offline']);
	Assert::same(3, $exit);
	Assert::contains('and the project has none; write it with `dresscode init` first.', $err);

	// the paths named narrow the configured ones
	file_put_contents("$root/src/Other.php", "<?php\n");
	[, $out] = runApp($root, ['upgrade', '--no-types', '--offline', '--format', 'json', '--paths', 'src/Other.php']);
	$offer = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same([1, []], [$offer['files'], $offer['topics']]);
	FileSystem::delete($root);
});


test('upgrade --plan writes the steps, --run makes them up to the raise of Composer, --continue the rest', function () {
	$root = createTempDir('upgrade-plan');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\n");
	$code = "<?php\n\nfunction run(\$handle, int \$count = null): void\n{\n\tcurl_close(\$handle);\n\tstrftime('%Y');\n}\n";
	file_put_contents("$root/src/Code.php", $code);
	[$exit, , $err] = runApp($root, ['upgrade', '--continue']);
	Assert::same(3, $exit);
	Assert::contains('dresscode-upgrade.neon` cannot be read', $err);

	[$exit, $out, $err] = runApp($root, ['upgrade', 'php', '8.4', '--plan', '--no-types', '--offline', '--no-color']);
	Assert::same('', $err);
	Assert::same(0, $exit);
	Assert::match(<<<'XX'
		%A%Plan       php 8.4
		  1  fix       works on the versions allowed today  php, upgrading.php.implicitNullable
		  2  composer  raise php to ^8.4
		%A%Tests      not run, no `--test-command` given
		XX, $out);
	Assert::same($code, file_get_contents("$root/src/Code.php"));
	Assert::false(is_file("$root/dresscode-upgrade.neon"));
	// an agent reads the same text without colors
	Assert::same($out, runApp($root, ['upgrade', 'php', '8.4', '--plan', '--no-types', '--offline', '--format', 'agent'])[1]);

	[$exit, $out, $err] = runApp($root, ['upgrade', 'php', '8.4', '--run', '--no-types', '--offline', '--no-color']);
	Assert::same('', $err);
	Assert::same(3, $exit);
	Assert::match(<<<'XX'
		%A%Saved      dresscode-upgrade.neon
		Step 1     php 1 file, upgrading.php.implicitNullable 1 file
		For you    1 place the fixes left
		  src/Code.php:5  Function `strftime()` is deprecated: since PHP 8.1.  [upgrading.php.deprecatedApi]
		Waiting    step 2, raise php to ^8.4: run `composer require "php:^8.4" -W`, then `dresscode upgrade --continue`
		XX, $out);
	Assert::same(
		"<?php\n\nfunction run(\$handle, ?int \$count = null): void\n{\n\tstrftime('%Y');\n}\n",
		file_get_contents("$root/src/Code.php"),
	);

	// what the plan leaves for a human, the code as it stands
	[$exit, $out] = runApp($root, ['upgrade', '--tasks', '--no-types', '--no-color']);
	Assert::same(1, $exit);
	Assert::match(<<<'XX'
		%A%For you    1 place the fixes left
		  src/Code.php:5  Function `strftime()` is deprecated: since PHP 8.1.  [upgrading.php.deprecatedApi]
		FOUND  places of the plan are left for you
		XX, $out);

	// a saved plan is run by --continue, which waits until the constraint is raised
	[$exit, , $err] = runApp($root, ['upgrade', 'php', '8.4', '--run', '--no-types', '--offline']);
	Assert::same(3, $exit);
	Assert::contains('The project has a saved plan, `dresscode-upgrade.neon`, which `--continue` runs', $err);
	Assert::same(3, runApp($root, ['upgrade', '--continue', '--no-types', '--no-color'])[0]);

	// as data, the run with the plan it ran, its warnings on standard error
	[$exit, $out, $err] = runApp($root, ['upgrade', '--continue', '--no-types', '--format', 'json']);
	Assert::same([3, ''], [$exit, $err]);
	$run = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same([1, 'composer', 2], [$run['version'], $run['outcome']['stop'], $run['outcome']['step']]);
	Assert::same([['step' => 1, 'topic' => 'php', 'files' => []]], array_slice($run['outcome']['fixed'], 0, 1));
	Assert::same('upgrading.php.deprecatedApi', $run['outcome']['tasks'][0]['decision']);
	Assert::same('composer', $run['plan']['steps'][1]['kind']);
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.4"}}');
	[$exit, $out] = runApp($root, ['upgrade', '--continue', '--no-types', '--no-color']);
	Assert::same(1, $exit);
	Assert::match(<<<'XX'
		%A%Step 3     php left nothing to fix
		For you    1 place the fixes left
		  src/Code.php:5  Function `strftime()` is deprecated: since PHP 8.1.  [upgrading.php.deprecatedApi]
		Waiting    step 3 leaves the places above: %a%
		XX, $out);

	// a task confirmed with a reason is done
	$code = str_replace(
		"\tstrftime",
		"\t// dresscode:ignore upgrading.php.deprecatedApi -- no replacement yet\n\tstrftime",
		FileSystem::read("$root/src/Code.php"),
	);
	file_put_contents("$root/src/Code.php", $code);
	[$exit, $out] = runApp($root, ['upgrade', '--continue', '--no-types', '--no-color']);
	Assert::same(0, $exit);
	Assert::contains('UPGRADED  every step of the plan is done', $out);
});


test('upgrade names the package of upgrading data a library of the project has, which DressCode lacks', function () {
	$root = createTempDir('offer-missing-data');
	mkdir("$root/src");
	file_put_contents("$root/src/Code.php", "<?php\n");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.2", "nette/utils": "^4.0", "acme/mail": "^1.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\ncacheDir: cache\n");
	FileSystem::write("$root/cache/data-packages.json", '{"dresscode/upgrade-nette": ["nette/", "latte/latte"]}');

	[$exit, $out] = runApp($root, ['upgrade', 'php', '8.4', '--no-types', '--offline', '--no-color']);
	Assert::same(0, $exit);
	Assert::contains(
		"No data    nette/utils: the upgrading data are in `dresscode/upgrade-nette`, which `dresscode upgrade --today` installs\n",
		$out,
	);
});


test('upgrade refuses what the offer cannot measure, and an option of --today without it', function () use ($root) {
	foreach ([
		[['upgrade', '--modernize'], '`--modernize` goes with `--today`; %a%'],
		[['upgrade', '--max-warnings', '0'], '`--max-warnings` goes with `--today`; %a%'],
		[['upgrade', 'acme/mail'], 'Name the version of `acme/mail` to upgrade to, as `upgrade acme/mail 4.0`.'],
		[['upgrade', 'php', 'next'], 'The version to upgrade to is written as `8.4` or `4.0`, `next` given.'],
		[['upgrade', 'php', '--today'], '`--today` upgrades to the versions `composer.json` allows, so it takes no target; %a%'],
		[['upgrade', '--format', 'lines'], 'The offer is written as `console`, `agent` or `json`; `lines` is a format of `--today`.'],
		[['upgrade', '--today', '--plan'], '`--plan` belongs to the plan of an upgrade, and `--today` makes none.'],
		[['upgrade', '--commit'], '`--step`, `--commit` and `--yes` go with `--run` or `--continue`, which run the plan.'],
		[['upgrade', '--test-command', 'x'], '`--test-command` and `--quick-test-command` go into a plan made with %a%'],
		[['upgrade', 'php', '--continue'], '`--continue` runs the saved plan as it is, so it takes no target and makes no plan; %a%'],
		[
			['upgrade', '--tasks', '--continue'],
			'`--tasks` lists what the saved plan leaves, so it takes no target and makes or runs no plan.',
		],
		[['upgrade', '--today', '--tasks'], '`--tasks` belongs to the plan of an upgrade, and `--today` makes none.'],
	] as [$args, $message]) {
		[$exit, , $err] = runApp($root, $args);
		Assert::same(3, $exit);
		Assert::match("%A%$message%A%", $err);
	}

	// outside the repository, whose composer.json it would find
	$empty = sys_get_temp_dir() . '/dresscode-offer-empty-' . getmypid();
	FileSystem::createDir($empty);
	[$exit, , $err] = runApp($empty, ['upgrade']);
	FileSystem::delete($empty);
	Assert::same(3, $exit);
	Assert::contains('There is nothing to measure', $err);
});


test('fix --stdin is a formatter: the whole text on stdout, nothing else, and exit 0 whatever is left', function () use ($root) {
	file_put_contents(
		"$root/formatter.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden', 'riskyRename' => 'forbidden']], paths: ['src'], excludePaths: ['src/generated'], targets: ['php' => '8.3']);\n",
	);
	$format = fn(string $input, array $options = [], string $path = 'src/x.php') => runApp(
		$root,
		array_values(['fix', '--config', "$root/formatter.php", ...$options, '--stdin', $path]),
		$input,
	);

	// the bytes as they came where nothing changes, the line endings and a missing last one included
	Assert::same([0, "<?php\r\n\$x;", ''], $format("<?php\r\n\$x;"));
	Assert::same([0, '', ''], $format(''));
	// a risky fix the run does not make, an error of a check, leaves the exit code at 0
	Assert::same([0, "<?php\n\$b;\n\$r;\n", ''], $format("<?php\n\$a;\n\$r;\n"));
	// a warning of the configuration is not printed
	Assert::same([0, "<?php\n\$b;\n", ''], $format("<?php\n\$a;\n", ['--set', 'classes.instantiationWrapping=forbidden']));
	// an excluded path the caller asks to skip gives the text back as it came
	Assert::same([0, "<?php\n\$a;\n", ''], $format("<?php\n\$a;\n", ['--skip-excluded'], 'src/generated/x.php'));
	Assert::same([0, "<?php\n\$a;\n", ''], $format("<?php\n\$a;\n", ['--skip-excluded'], 'lib/x.php'));
	Assert::same([0, "<?php\n\$b;\n", ''], $format("<?php\n\$a;\n", [], 'src/generated/x.php'));

	// a syntax error gives the text back with one line on stderr
	[$code, $out, $err] = $format("<?php\n\$a = ;\n");
	Assert::same([1, "<?php\n\$a = ;\n"], [$code, $out]);
	Assert::match("src/x.php:2: Syntax error: %a%\n", $err);
	Assert::same(1, substr_count($err, "\n"));

	// the options of a report have no place in it
	foreach ([['--format', 'json'], ['--diff'], ['--max-warnings', '0'], ['--ask-risky']] as $options) {
		[$code, $out, $err] = $format("<?php\n\$a;\n", $options);
		Assert::same([3, ''], [$code, $out], $options[0]);
		Assert::contains(
			"`fix --stdin` writes the fixed code alone, so it takes no `$options[0]`; `check --stdin` reports on the code in any format.",
			$err,
		);
	}
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
	Assert::same('foo($a, $b)', $data['decisions']['spacing.call']['standards']['nette']);
	Assert::contains('no_spaces_after_function_name', $data['decisions']['spacing.call']['covers']);
});


test('migrate config translates a foreign configuration and says what it could not', function () use ($root) {
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
	$dir = createTempDir('import');
	[$code, $out, $err] = runApp($root, ['migrate', 'config', "$root/phpcs.xml", '--output', "$dir/dresscode.php"]);
	Assert::same([0, ''], [$code, $out]);
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return new Config(\n"
		. "\tuse: ['psr12'],\n"
		. "\tpaths: ['src'],\n"
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
		. "\t\t\t'bodyStart' => [\n"
		. "\t\t\t\t'class' => 0,\n"
		. "\t\t\t],\n"
		. "\t\t\t'bodyEnd' => [\n"
		. "\t\t\t\t'class' => 0,\n"
		. "\t\t\t],\n"
		. "\t\t\t'aroundMethods' => 1,\n"
		. "\t\t\t'aroundInterfaceMethods' => 1,\n"
		. "\t\t\t'aroundDeclarations' => 1,\n"
		. "\t\t],\n"
		. "\t],\n"
		. ");\n",
		file_get_contents("$dir/dresscode.php"),
	);
	Assert::match(
		"Written `%a%`.\n\nRead 5 rules; set 12 decisions and 1 preset.\n"
		. "  `Squiz.WhiteSpace.FunctionSpacing` leaves the blank lines around classes alone, while `blankLines` sets them together with those around functions.\n"
		. "  No DressCode rule covers `Squiz.Nonsense.DoesNotExist`.\n",
		$err,
	);

	file_put_contents(
		"$root/fixer.php",
		"<?php\nreturn new class {\n\tpublic function getRules(): array\n\t{\n\t\treturn ['cast_spaces' => ['space' => 'none']];\n\t}\n};\n",
	);
	[$code] = runApp($root, ['migrate', 'config', 'fixer.php', '-o', "$dir/fixer.php"]); // relative to the working directory of the run
	Assert::same(0, $code);
	Assert::contains("\t\t'spacing' => [\n\t\t\t'cast' => '(int)\$x',\n\t\t],\n", (string) file_get_contents("$dir/fixer.php"));

	// the file is written in the format of its extension, as a configuration the run reads
	[$code, $out] = runApp($dir, ['migrate', 'config', "$root/phpcs.xml"]);
	Assert::same([0, ''], [$code, $out]);
	$neon = (string) file_get_contents("$dir/dresscode.neon");
	Assert::match("use: psr12\n\npaths: [.]\n\nmultiline:\n\ttrailingComma:\n\t\tarray: required\n%A%", $neon);
	Assert::same(
		'required',
		DressCode\Config\NeonReader::read("$dir/dresscode.neon")->decisions['multiline']['trailingComma']['array'] ?? null,
	);

	[$code, , $err] = runApp($dir, ['migrate', 'config', "$root/phpcs.xml"]);
	Assert::same(3, $code);
	Assert::contains('exists already', $err);
	[$code, , $err] = runApp($root, ['migrate', 'config', "$root/phpcs.xml", '-o', "$dir/dresscode.yaml"]);
	Assert::same(3, $code);
	Assert::contains('its extension is `.neon` or `.php`', $err);
});


test('migrate config without a file finds the configuration the project has, and runs one of PHP CS Fixer only when agreed', function () {
	$project = createTempDir('import-find');
	[$code, , $err] = runApp($project, ['migrate', 'config']);
	Assert::same(3, $code);
	Assert::contains('The project has no configuration of PHP CS Fixer, PHP_CodeSniffer or Laravel Pint', $err);

	// the preset of Laravel Pint is the standard, its rules those of PHP CS Fixer
	file_put_contents("$project/pint.json", '{"preset": "per", "rules": {"cast_spaces": {"space": "none"}}}');
	[$code, , $err] = runApp($project, ['migrate', 'config']);
	Assert::same(0, $code);
	Assert::contains('Found `pint.json` of Laravel Pint.', $err);
	Assert::match("use: perCs\n%A%cast: '(int)\$x'%A%", (string) file_get_contents("$project/dresscode.neon"));

	$project = createTempDir('import-fixer');
	file_put_contents(
		"$project/.php-cs-fixer.dist.php",
		"<?php\nfile_put_contents(__DIR__ . '/ran', '');\nreturn ['cast_spaces' => true];\n",
	);
	file_put_contents("$project/composer.json", '{"scripts": {"cs": "php-cs-fixer fix --config=.php-cs-fixer.dist.php"}}');
	[$code, , $err] = runApp($project, ['migrate', 'config']);
	Assert::same(3, $code);
	Assert::contains(
		'`.php-cs-fixer.dist.php` is PHP, which reading runs, so it is read only when named: `dresscode migrate config .php-cs-fixer.dist.php`.',
		$err,
	);
	Assert::false(is_file("$project/ran"));

	[$code, $out, $err] = runApp($project, ['migrate', 'config'], "\n", interactive: true);
	Assert::same(0, $code);
	Assert::contains('Found `.php-cs-fixer.dist.php` of PHP CS Fixer, run by `composer cs`.', $err);
	Assert::contains('Read it? [y/N]', $out);
	Assert::contains('Nothing written.', $err);
	Assert::false(is_file("$project/ran"));
	Assert::false(is_file("$project/dresscode.neon"));

	[$code] = runApp($project, ['migrate', 'config'], "y\n", interactive: true);
	Assert::same(0, $code);
	Assert::true(is_file("$project/ran"));
	Assert::true(is_file("$project/dresscode.neon"));
});


test('migrate config writes the paths the configuration names, and keeps the decisions of a sniff where it leaves files out', function () {
	$project = createTempDir('import-scope');
	foreach (['src/A.php', 'src/drivers/B.php', 'tests/C.php'] as $file) {
		@mkdir(dirname("$project/$file"), recursive: true); // @ directory may already exist
		file_put_contents("$project/$file", "<?php\n");
	}

	file_put_contents("$project/phpcs.xml", <<<'XX'
		<?xml version="1.0"?>
		<ruleset name="Demo">
			<file>src</file>
			<rule ref="PSR12"/>
			<rule ref="PSR1.Methods.CamelCapsMethodName">
				<exclude-pattern>*/drivers/*</exclude-pattern>
			</rule>
		</ruleset>
		XX);
	[$code] = runApp($project, ['migrate', 'config']);
	Assert::same(0, $code);
	$neon = (string) file_get_contents("$project/dresscode.neon");
	Assert::contains("use: psr12\n\npaths: [src]\n\n", $neon);
	Assert::match(
		"%A%overrides:\n\t# where PSR1.Methods.CamelCapsMethodName is left out by your formatter\n\t-\n%A%src/drivers%A%method: keep\n",
		$neon,
	);
});


test('migrate config points at the phpcs suppression comments of the files it writes the configuration for', function () {
	$project = createTempDir('import-suppressions');
	foreach (['src/A.php', 'src/B.php', 'tests/C.php'] as $file) {
		@mkdir(dirname("$project/$file"), recursive: true); // @ directory may already exist
		file_put_contents("$project/$file", "<?php\n// phpcs:ignore Generic.Files.LineLength\n");
	}

	file_put_contents(
		"$project/phpcs.xml",
		"<?xml version=\"1.0\"?>\n<ruleset name=\"Demo\">\n\t<file>src</file>\n\t<rule ref=\"PSR12\"/>\n</ruleset>\n",
	);
	[$code, , $err] = runApp($project, ['migrate', 'config']);
	Assert::same(0, $code);
	Assert::contains("\n2 phpcs suppression comments: `dresscode migrate suppressions` rewrites them.\n", $err);
});


test('errors go to stderr with exit code 3', function () use ($root) {
	[$code, $out, $err] = runApp($root, ['check', '--nope']);
	Assert::same(3, $code);
	Assert::same('', $out);
	Assert::match("Error: Unknown option `--nope`.\n\nUsage:%A%", $err);
	[$code, , $err] = runApp($root, ['check', '--use', 'test/none=true']);
	Assert::same(3, $code);
	Assert::match(
		'Error: Option `--use`: expects a preset or a plugin, `test/none=true` given; a decision is set by `--set path=value`.%A%',
		$err,
	);
	[$code, , $err] = runApp($root, ['check', '--use', 'test/nonee']);
	Assert::same(3, $code);
	Assert::same("Error: The command line: Unknown preset `test/nonee`.\n", $err);
	[$code, , $err] = runApp($root, ['check', '--format', 'xml']);
	Assert::same(3, $code);
	Assert::match(
		'Error: Option `--format`: expects `console`, `agent`, `lines`, `github`, `json` or `checkstyle`, `xml` given.%A%',
		$err,
	);
	[$code, , $err] = runApp($root, ['config', '--format', 'markdown']);
	Assert::same(3, $code);
	Assert::match('Error: Option `--format`: expects `console` or `json`, `markdown` given.%A%', $err);
	[$code, , $err] = runApp($root, ['explain', '--format', 'json']);
	Assert::same(3, $code);
	Assert::match('Error: Option `--format`: expects `console` or `markdown`, `json` given.%A%', $err);
	[$code, , $err] = runApp($root, ['check', '--set', 'project.rename={']);
	Assert::same(3, $code);
	Assert::match('Error: Option `--set`: expects the value in NEON, `project.rename={` given:%A%', $err);
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
	Assert::match('Error: Unknown command `wat`.%A%', $err);
});


test('a named file the configuration excludes is checked unless the caller asks to skip it', function () {
	$root = createConsoleProject();
	mkdir("$root/vendor");
	file_put_contents("$root/vendor/v.php", "<?php\n\$a;\n");
	[$code, $out] = runApp($root, ['check', '-f', 'lines', 'vendor/v.php']);
	Assert::same(1, $code);
	Assert::same("vendor/v.php:2:1: error: Rename \$a. [project.rename]\n", $out);
	[$code, $out] = runApp($root, ['fix', 'vendor/v.php', '--skip-excluded']);
	Assert::same(0, $code);
	Assert::match("%A%Nothing to fix: no file in %a%v.php\n", $out);
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/vendor/v.php"));

	// and so is one the configured paths do not hold
	file_put_contents("$root/tool.php", "<?php\n\$a;\n");
	[$code] = runApp($root, ['fix', 'tool.php', '--skip-excluded']);
	Assert::same(0, $code);
	Assert::same("<?php\n\$a;\n", file_get_contents("$root/tool.php"));
});


test('a run inside GitHub Actions annotates without being told to', function () use ($root) {
	putenv('GITHUB_ACTIONS=true');
	putenv("GITHUB_WORKSPACE=$root");
	[$code, $out] = runApp($root, ['check']);
	Assert::same(1, $code);
	Assert::match(
		"%A%src/a.php:2  project.rename\n::error file=src/a.php,line=2,col=1,title=project.rename::Rename \$a.\n1 violation in 2 files\n",
		$out,
	);
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
		Assert::match(
			"Error: No `dresscode.neon` or `dresscode.php` found in `%a%` or above it, so there is no dress code to check against. Run `dresscode init`%A%.\nSee https://dresscode.run/cli#init\n",
			$err,
		);
	}

	[$code, , $err] = runApp($dir, ['check', '--use', 'perCs']);
	Assert::same(3, $code);
	Assert::match('Error: No paths given and none configured.%A%', $err);
});


test('a file --use names is relative to the working directory, one the configuration uses to its root', function () {
	$dir = createTempDir('console-use');
	mkdir("$dir/config");
	file_put_contents("$dir/config/dresscode.php", "<?php\nreturn new DressCode\\Config(use: ['base.neon']);\n");
	file_put_contents("$dir/config/base.neon", "blankLines:\n\taroundMethods: 3\n");
	file_put_contents("$dir/style.neon", "blankLines:\n\tafterImports: 2\n");
	[$code, $out] = runApp($dir, ['config', '--config', "$dir/config/dresscode.php", '--use', 'style.neon']);
	Assert::same(0, $code);
	Assert::match("%A%\tafterImports: 2 %A%/console-use/style.neon\n%A?%", $out);
	Assert::match("%A%\taroundMethods: 3 %A%/console-use/config/base.neon\n%A?%", $out);
});


test('a file --config names is relative to the working directory', function () {
	$dir = createTempDir('console-config');
	mkdir("$dir/config");
	file_put_contents(
		"$dir/config/style.php",
		"<?php\nreturn new DressCode\\Config(decisions: ['blankLines' => ['aroundMethods' => 3]]);\n",
	);
	[$code, $out, $err] = runApp($dir, ['config', '--config', 'config/style.php']);
	Assert::same('', $err);
	Assert::same(0, $code);
	Assert::match("%A%\taroundMethods: 3 %A%", $out);
});


test('a baseline is generated by the baseline command over what a fix leaves and baselines what it finds', function () {
	$root = createConsoleProject();
	file_put_contents(
		"$root/baseline.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['report' => 'forbidden']], paths: ['src'], baseline: 'baseline.neon');\n",
	);
	// a fix would still rename in src/a.php and src/b.php does not parse, so what a fix leaves is not known yet
	file_put_contents("$root/src/b.php", "<?php\n\$x = ;\n");
	[$code, , $err] = runApp($root, ['baseline', '--config', "$root/baseline.php", '--set', 'project.rename=forbidden']);
	Assert::same(1, $code);
	Assert::same(
		"Error: The baseline is generated over code a fix leaves alone: a fix would change 1 file, 1 file failed. Run `fix` first.\n  src/a.php\n  src/b.php\nSee https://dresscode.run/suppressing#baseline\n",
		$err,
	);
	Assert::false(is_file("$root/baseline.neon"));
	file_put_contents("$root/src/b.php", "<?php\n\$x;\n");
	foreach (['--fix-risky', '--only', '--stdin', '--diff', '--max-warnings'] as $option) {
		[$code, , $err] = runApp($root, ['baseline', '--config', "$root/baseline.php", $option, 'x']);
		Assert::same(3, $code, $option);
		Assert::match("Error: %a%`$option`%a%\n%A%", $err);
	}

	// a baseline that cannot be read is replaced, and it is no obstacle to the migration of the comments
	file_put_contents("$root/baseline.neon", "a: [b\n");
	Assert::same(0, runApp($root, ['migrate', 'suppressions', '--config', "$root/baseline.php"])[0]);
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
	Assert::match(
		"%A%Warning: 1 entry of the baseline no longer matches a violation; regenerate it with `dresscode baseline`.\n\nOK  1 violation in the baseline in 2 files\n",
		$out,
	);
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

	// with no baseline named it gets the default name, in the format of the configuration, which a run finds by itself
	file_put_contents(
		"$root/report.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['report' => 'forbidden']], paths: ['src']);\n",
	);
	[$code, $out] = runApp($root, ['baseline', '--config', "$root/report.php"]);
	Assert::same(0, $code);
	Assert::same("Baseline with 2 violations written to `dresscode-baseline.php`.\n", $out);
	Assert::match("%A%'decision' => 'project.report',%A%", (string) file_get_contents("$root/dresscode-baseline.php"));
	[$code, $out] = runApp($root, ['check', '--config', "$root/report.php"]);
	Assert::same(0, $code);
	Assert::match("%A%Baseline   %a%dresscode-baseline.php\n%A%OK  2 violations in the baseline%A%", $out);
	unlink("$root/dresscode-baseline.php");

	// a baseline the configuration names must exist
	file_put_contents(
		"$root/missing.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, paths: ['src'], baseline: 'missing-baseline.neon');\n",
	);
	[$code, , $err] = runApp($root, ['check', '--config', "$root/missing.php"]);
	Assert::same(3, $code);
	Assert::contains(
		'missing-baseline.neon` does not exist; `dresscode baseline` writes it.',
		$err,
	);
	Assert::same(0, runApp($root, ['baseline', '--config', "$root/missing.php"])[0]);
	unlink("$root/missing-baseline.neon");
});


test('workers give the same results as the in-process run', function () use ($root) {
	file_put_contents(
		"$root/jobs.php",
		"<?php\nreturn new DressCode\\Config(paths: ['jobs'], decisions: ['file' => ['trailingWhitespace' => 'forbidden']]);\n",
	);
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


test('a worker builds a rule of a plugin by the factory of its manifest, as the process alone does', function () use ($root) {
	$plugin = var_export(__DIR__ . '/fixtures/factory-plugin.php', return: true);
	file_put_contents(
		"$root/factory.php",
		"<?php\nrequire_once $plugin;\nreturn new DressCode\\Config(use: [WorkerFactoryPlugin::class], paths: ['jobs'], decisions: ['workerFactory' => ['variables' => 'forbidden']]);\n",
	);
	$config = ['--config', "$root/factory.php", '--no-cache'];
	[, $expected] = runApp($root, ['check', ...$config, '--jobs', '1']);
	[$code, $out, $err] = runApp($root, ['check', ...$config, '--jobs', '2']);
	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::same($expected, $out);
	Assert::contains('A variable, built by the factory.', $out);
});


test('a worker gets a value starting with a dash as a value', function () use ($root) {
	@unlink("$root/-profile.json"); // @ may not exist
	[$code, , $err] = runApp($root, ['check', '--config', "$root/jobs.php", '--no-cache', '--jobs', '2', '--profile=-profile.json']);
	Assert::same('', $err);
	Assert::same(0, $code);
	Assert::true(is_file("$root/-profile.json"));
});


test('a file a worker cannot write fails alone, the others are fixed', function () use ($root) {
	file_put_contents(
		"$root/readonly.php",
		"<?php\nreturn new DressCode\\Config(paths: ['readonly'], decisions: ['file' => ['trailingWhitespace' => 'forbidden']]);\n",
	);
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
	file_put_contents(
		"$root/profile.php",
		"<?php\nreturn new DressCode\\Config(paths: ['profile'], decisions: ['casing' => ['keyword' => 'lowercase'],'file' => ['trailingWhitespace' => 'forbidden']]);\n",
	);
	@mkdir("$root/profile"); // @ directory may already exist
	for ($i = 0; $i < 8; $i++) {
		file_put_contents("$root/profile/$i.php", "<?php\nIF (\$a) { \$b; }\t\n");
	}

	$counts = [];
	foreach (['1', '2'] as $jobs) {
		[$code, , $err] = runApp(
			$root,
			['check', '--config', "$root/profile.php", '--jobs', $jobs, '--profile', "$root/profile-$jobs.json"],
		);
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
			array_map(
				fn($entry) => $entry['calls'],
				array_intersect_key($profile['phases'], ['parse' => 0, 'passes' => 0, 'print' => 0, 'gaps' => 0]),
			),
		];
		array_walk($count, ksort(...)); // the tables are ordered by time, which differs between runs
		$counts[] = $count;
	}

	Assert::same($counts[0], $counts[1]);
	if (findPhpCliBinary() !== null) {
		Assert::count(2 + 1, json_decode(FileSystem::read("$root/profile-2.json"), associative: true)['peakMemory']); // the parent and two workers
	}

	// writing a baseline is a run of check too, whether it ends with one or with code a fix would change
	runApp($root, ['baseline', '--config', "$root/profile.php", '--profile', "$root/profile-baseline.json"]);
	Assert::same(8, json_decode(FileSystem::read("$root/profile-baseline.json"), associative: true)['files']);
});


test('a worker has the settings of the process the ini file alone would not give it', function () use ($root) {
	// the configuration turns the rule on by a setting, which a worker has only when it is handed over
	file_put_contents(
		"$root/ini.php",
		"<?php\nreturn new DressCode\\Config(paths: ['ini'], decisions: ['file' => ['trailingWhitespace' => ini_get('user_agent') === 'dresscode-test' ? 'forbidden' : 'keep']]);\n",
	);
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
	file_put_contents(
		"$root/jobs.php",
		"<?php\nreturn new DressCode\\Config(paths: ['jobs'], baseline: 'jobs-baseline.neon', decisions: ['mistakes' => ['strictArgument' => 'required']]);\n",
	);
	$config = ['--config', "$root/jobs.php", '--no-cache'];

	// the risky fixes the configuration does not accept are what a fix leaves
	runApp($root, ['baseline', ...$config, '--jobs', '1']);
	$single = (string) file_get_contents("$root/jobs-baseline.neon");
	runApp($root, ['baseline', ...$config, '--jobs', '3']);
	Assert::same($single, (string) file_get_contents("$root/jobs-baseline.neon"));
	Assert::match('%A%decision: mistakes.strictArgument%A%', $single);

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


test('migrate suppressions rewrites phpcs comments to the dresscode form', function () {
	$root = createConsoleProject();
	file_put_contents(
		"$root/src/s.php",
		"<?php\n// phpcs:ignoreFile\n/**\n * @phpcsSuppress Squiz.WhiteSpace.SuperfluousWhitespace\n */\nclass S\n{\n\t// phpcs:ignore Squiz.WhiteSpace.SuperfluousWhitespace, Generic.Metrics.CyclomaticComplexity -- why\n\tpublic \$a; // phpcs:disable Squiz.WhiteSpace.SuperfluousWhitespace\n\t// phpcs:enable\n}\n",
	);
	[$code, $out, $err] = runApp($root, ['migrate', 'suppressions', 'src/s.php']);
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
	[$code, $out] = runApp($root, ['migrate', 'suppressions', 'src/s.php']);
	Assert::same(0, $code);
	Assert::same("Migrated 0 suppression comments in 0 files.\n", $out);

	// an ignore before code on its line keeps covering that line, so there is nothing to review
	file_put_contents("$root/src/t.php", "<?php\nfoo();\n/* phpcs:ignore Squiz.WhiteSpace.SuperfluousWhitespace */ \$a = 1;\n");
	[, $out] = runApp($root, ['migrate', 'suppressions', 'src/t.php']);
	Assert::same("Migrated 1 suppression comment in 1 file.\n", $out);
});


test('migrate suppressions narrows a directory holding the configured paths to them', function () {
	$root = createConsoleProject();
	mkdir("$root/other");
	file_put_contents("$root/other/s.php", "<?php\n// phpcs:ignoreFile\n");
	[, $out] = runApp($root, ['migrate', 'suppressions', '.']);
	Assert::same("Migrated 0 suppression comments in 0 files.\n", $out);
	Assert::same("<?php\n// phpcs:ignoreFile\n", file_get_contents("$root/other/s.php"));
	[, $out] = runApp("$root/other", ['migrate', 'suppressions', '.']);
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
			"~intentionally ==~": [expressions.comparison.loose]

		qualification: keep

		upgrading:
			syntax:
				promotedProperties: keep

		file:
			lineLength: 100
			lineLengthExcept: []
			longLines: forbidden

		overrides:
			- paths: [src/generated]
			  indentation: keep

		paths: [src]

		XX);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon"]);
	Assert::same(0, $code);
	Assert::match('%A%Use        dresscode/psr12%A%', $out);
	Assert::match('%A%Comments   silence decisions on their line%A%      ~intentionally ==~ %a%expressions.comparison.loose%A%', $out);
	Assert::match('%A%Style%a%4 spaces, the line ending each file mostly has, lines of up to 100 characters%A%', $out);
	Assert::match('%A%Decisions  %d% of %d% set by a layer, the others asking for nothing%A%', $out);
	Assert::match("%A%\nfile:\n%A%\tlineLength: 100 %s%# the configuration\n%A?%", $out);
	Assert::match("%A%\nqualification:\n%A%\tfunction:\n%A?%\t\tglobal: keep %s%# the configuration\n%A?%", $out);
	Assert::match("%A%\tcall: 'foo(\$a, \$b)' %s%# dresscode/psr12\n%A?%", $out);
	Assert::match("%A%\tsyntax:\n\t\tpromotedProperties: keep %s%# the configuration\n%A?%", $out);

	// for one file it is what the run uses for that file
	[, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--file', 'src/generated/x.php']);
	Assert::match('%A%File       src%a%generated%a%x.php%A%', $out);
	Assert::match("%A%\nindentation:\n%A%\tunit: keep %s%# the override for src/generated\n%A?%", $out);

	// a preset alone, whatever the configuration is
	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--preset', 'psr12']);
	Assert::same(0, $code);
	Assert::match('%A%Config     none, using psr12%A%', $out);
	Assert::match("%A%\tlineLength: 120 %s%# dresscode/psr12\n%A?%", $out);
	[, $out] = runApp($root, ['config', '--preset', 'psr12', '--set', 'file.lineLength=90']);
	Assert::match("%A%\tlineLength: 90 %s%# the command line\n%A?%", $out);

	[$code, $out] = runApp($root, ['config', '--config', "$root/conf.neon", '--format', 'json']);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true);
	Assert::same(1, $data['version']);
	Assert::same(['psr12'], $data['use']);
	Assert::same(['php' => '8.4'], $data['targets']);
	Assert::same(4, $data['indent']);
	Assert::same(100, $data['lineLength']);
	Assert::same('uncertain', $data['nameResolution']);
	Assert::same(['~intentionally ==~' => ['expressions.comparison.loose']], $data['suppressionComments']);
	Assert::same([], $data['decisions']['file.lineLengthExcept']['value']);
	Assert::false($data['rules'][DressCode\Rules\Namespaces\GlobalNameQualificationRule::class]['active']);
	Assert::same(
		['reason' => 'turnedOff', 'message' => 'its decisions are `keep`'],
		$data['rules'][DressCode\Rules\Namespaces\GlobalNameQualificationRule::class]['inactive'],
	);
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
	$write(", severity: [ConsoleRename::class => 'warning', ConsoleReport::class => 'warning']");
	[$code, $out] = runApp($root, ['check', '--config', "$root/exit.php", '--no-cache']);
	Assert::same(0, $code);
	Assert::match('%A%  warning  2:1  Rename $a.  project.rename%A%FOUND  3 warnings in 2 files%A%', $out);
	Assert::same(0, $run(['--max-warnings', '3']));
	Assert::same(1, $run(['--max-warnings', '2']));
	// and so it is over stdin
	/** @param list<string> $args */
	$stdin = fn(array $args) => runApp(
		$root,
		array_values(['check', '--config', "$root/exit.php", '--no-cache', ...$args, '--stdin', 'src/b.php']),
		"<?php\n\$x;\n",
	)[0];
	Assert::same(0, $stdin([]));
	Assert::same(1, $stdin(['--max-warnings', '0']));

	// a rule left as an error decides the exit code whatever the threshold says
	$write(", severity: [ConsoleReport::class => 'warning']");
	Assert::same(1, $run(['--max-warnings', '100']));

	// the command line says the severity of a section
	$write('');
	[$code, $out] = runApp($root, ['check', '--config', "$root/exit.php", '--no-cache', '--severity', 'project=warning']);
	Assert::same(0, $code);
	Assert::match('%A%  warning  2:1  Rename $a.  project.rename%A%FOUND  3 warnings in 2 files%A%', $out);

	// a syntax error is 1 even when every rule only warns
	$write(", severity: [ConsoleRename::class => 'warning', ConsoleReport::class => 'warning']");
	Assert::same(0, $run());
	file_put_contents("$root/src/broken.php", "<?php\n\$a = ;\n");
	Assert::same(1, $run());
});


test('exit codes: a file that failed is 2, a mistake of the command line or of the configuration is 3', function () {
	$root = createConsoleProject();
	file_put_contents(
		"$root/failing.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['failing' => 'forbidden']], paths: ['src']);\n",
	);
	[$code, $out, $err] = runApp($root, ['check', '--config', "$root/failing.php", '--no-cache']);
	Assert::same(2, $code);
	Assert::same('', $err);
	Assert::contains('Broken rule', $out);
	// over stdin too, and the document of the format is whole
	[$code, $out] = runApp($root, ['check', '--config', "$root/failing.php", '--format', 'json', '--stdin', 'src/a.php'], "<?php\n\$a;\n");
	Assert::same(2, $code);
	Assert::contains('Broken rule', json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR)['files'][0]['failure']['message']);
	[$code, $out, $err] = runApp($root, ['fix', '--config', "$root/failing.php", '--stdin', 'src/a.php'], "<?php\n\$a;\n");
	Assert::same(2, $code);
	Assert::same("<?php\n\$a;\n", $out);
	Assert::match("src%[/\\\\]%a.php: Rule `ConsoleFailing` failed in `src%[/\\\\]%a.php`: Broken rule\n", $err);
	Assert::same(1, substr_count($err, "\n"));
	// an error of the tool itself
	file_put_contents("$root/throwing.php", "<?php\nthrow new LogicException('boom');\n");
	[$code, , $err] = runApp($root, ['check', '--config', "$root/throwing.php"]);
	Assert::same(2, $code);
	Assert::match("Error: boom\nLogicException in %a%throwing.php:2\n#0 %A%", $err);

	file_put_contents(
		"$root/invalid.php",
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['rename' => 'forbidden']], paths: ['src'], baseline: 1);\n",
	);
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
		Assert::match("Error: Option `$option`: expects %a% given.%A%", $err);
	}
});


test('a risky fix waits for the run to allow it, and is a violation until it is made', function () {
	$root = createTempDir('risky');
	mkdir("$root/src");
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
	$config = "$root/risky.php";
	$write = fn(string $tail) => file_put_contents(
		$config,
		"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['riskyRename' => 'forbidden']], paths: ['src']$tail);\n",
	);

	// refused: reported, counted apart with the way to decide it, not fixed, and a change of what the code does
	// is an error the exit code says
	$write('');
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache']);
	Assert::same(1, $code);
	Assert::contains(
		'1 risky fix changes what the code does: decide it with `fix --ask-risky`, or accept its decision in `fixRisky`. See https://dresscode.run/configuration#risky-fixes',
		$out,
	);
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
	Assert::match("%A%Apply this risky fix? [y/n/a/q] \nREVIEWED  0 risky fixes made, 1 left\n", $out);
	Assert::same("<?php\n\$r;\n", (string) file_get_contents("$root/src/r.php"));
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--ask-risky'], "y\n", interactive: true);
	Assert::same(0, $code);
	Assert::match("%A%-\$r;\n+\$s;\nApply this risky fix? [y/n/a/q] \nREVIEWED  1 risky fix made, none left\n", $out);
	Assert::same("<?php\n\$s;\n", (string) file_get_contents("$root/src/r.php"));
	[$code, $out] = runApp($root, ['fix', '--config', $config, '--no-cache', '--ask-risky'], interactive: true);
	Assert::same(0, $code);
	Assert::match("%A%\nREVIEWED  no risky fixes to ask about\n", $out);
	file_put_contents("$root/src/r.php", "<?php\n\$r;\n");

	// or only for the decisions, rules and presets it names, with workers as without them
	foreach (['1', '2'] as $jobs) {
		file_put_contents("$root/src/r.php", "<?php\n\$r;\n");
		Assert::same(
			1,
			runApp(
				$root,
				['fix', '--config', $config, '--no-cache', '--jobs', $jobs, '--fix-risky=mistakes.strictArgument'],
			)[0],
		);
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
		['in_array(1, [1], false)', ", fixRisky: ['mistakes.strictArgument']", 1, 0],
		['in_array(1, [1])', '', 1, 1],
		['in_array(1, [1])', ", fixRisky: ['mistakes.strictArgument']", 0, 0],
	] as [$call, $tail, $remaining, $deferred]) {
		file_put_contents("$root/src/a.php", "<?php\n$call;\n");
		file_put_contents(
			$config,
			"<?php\nreturn new DressCode\\Config(paths: ['src']$tail, decisions: ['mistakes' => ['strictArgument' => 'required']]);\n",
		);

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


test('a decision the configuration only reports is never fixed, nor offered to the consent fixRisky gives', function () {
	$root = createTempDir('report-only');
	mkdir("$root/src");
	$config = "$root/reportOnly.php";
	$code = "<?php\nin_array(1, [1]);\n";
	file_put_contents("$root/src/a.php", $code);
	file_put_contents(
		$config,
		"<?php\nreturn new DressCode\\Config(paths: ['src'], fixRisky: ['mistakes.strictArgument'], reportOnly: ['mistakes'], decisions: ['mistakes' => ['strictArgument' => 'required']]);\n",
	);

	[, $out] = runApp($root, ['check', '--config', $config, '--no-cache', '--format', 'json']);
	$summary = json_decode($out, associative: true)['summary'];
	Assert::same([1, 0], [$summary['remaining'], $summary['refused']]);
	[$code1] = runApp($root, ['fix', '--config', $config, '--no-cache']);
	Assert::same(1, $code1);
	Assert::same($code, file_get_contents("$root/src/a.php"));
});


test('a risky fix only the types could decide is a warning until it is made, and the summary says how to decide it', function () {
	$root = createTempDir('type-unknown');
	mkdir("$root/src");
	file_put_contents("$root/src/a.php", "<?php\nin_array(\$a, \$b);\n");
	$config = "$root/strictComparisonArgumentRequired.php";
	file_put_contents(
		$config,
		"<?php\nreturn new DressCode\\Config(paths: ['src'], decisions: ['mistakes' => ['strictArgument' => 'required']]);\n",
	);

	[$code, $out] = runApp($root, ['check', '--config', $config, '--no-cache']);
	Assert::same(0, $code);
	Assert::match('%A%  risky  2:1  The `in_array()` call must pass `$strict = true`%A%', $out);
	Assert::match(
		'%A%1 risky fix waits, the type is unknown: set `typeAnalysis: phpstan`. See https://dresscode.run/types#enable%A%',
		$out,
	);
	Assert::same(1, runApp($root, ['check', '--config', $config, '--no-cache', '--max-warnings', '0'])[0]);
});


test(
	'config says of a decision with risky fixes whether the project accepts them, and a name that does nothing is a warning',
	function () use ($root) {
		$config = "$root/risky-config.php";
		file_put_contents(
			$config,
			"<?php\nreturn new DressCode\\Config(rules: ConsoleRules, decisions: ['project' => ['riskyRename' => 'forbidden'], 'mistakes' => ['strictArgument' => 'required']],"
			. " overrides: [new DressCode\\Override(['src'], new DressCode\\Profile(decisions: ['file' => ['strictTypes' => ['declaration' => 'required']]])), new DressCode\\Override(['legacy'], new DressCode\\Profile(decisions: ['file' => ['lineEnding' => 'LF']]))], fixRisky: [ConsoleRiskyRename::class, 'file.strictTypes.declaration', 'classes.internalClass.final'], paths: ['src']);\n",
		);

		[$code, $out] = runApp($root, ['config', '--config', $config]);
		Assert::same(0, $code);
		Assert::match("%A%\triskyRename: forbidden %s%# the configuration, risky fixes accepted\n%A?%", $out);
		Assert::match("%A%\tstrictArgument: required %s%# the configuration\n%A?%", $out);
		Assert::match("%A%\tstrictTypes:\n\t\tdeclaration: keep %s%# no layer, risky fixes accepted\n%A?%", $out);
		Assert::match("%A%\tinternalClass:\n\t\tfinal: keep %s%# no layer, risky fixes accepted\n%A?%", $out);

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
		Assert::contains('Decision `classes.internalClass.final` is named in `fixRisky` but runs nowhere; the entry does nothing.', $err);
		Assert::notContains('file.strictTypes.declaration', $err);
	},
);
