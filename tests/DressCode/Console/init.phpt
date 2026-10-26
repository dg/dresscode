<?php declare(strict_types=1);

/**
 * init writes a configuration measured from the code: what it writes, the loader reads and resolves to the
 * values it measured, and a decision the code does not make clearly enough is not written as a value.
 */

use DressCode\Config\{Loader, RunnerFactory};
use DressCode\Console\Application;
use DressCode\Measuring\{InProcessProbeRunner, ProjectFacts, Proposal, Scope};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/init.helpers.php';


$tabbed = "<?php\n\nclass A\n{\n\tpublic function b(): string\n\t{\n\t\treturn 'x' . 'y' . 'z';\n\t}\n}\n";


test('init writes what the code says, the loader reads it back as measured, and check runs with it', function () use ($tabbed) {
	$root = createProject('written', [
		'src/A.php' => $tabbed,
		'src/B.php' => str_replace('A', 'B', $tabbed),
		'src/C.php' => "<?php\n\n// @generated, and what a generator wrote says nothing about how the project writes\n",
		'tests/c.phpt' => "<?php\n\nfunction c(): void\n{\n\techo \"plain\";\n}\n",
		'tests/fixtures/generated.php' => "<?php\n    \$x = \"a\";\n",
		'composer.json' => '{}', // the project of its own, not the one the temporary directory lies in
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::match(<<<'XX'
		DRESS|CODE %a%  looking at %a% to write its `dresscode.neon`

		Project    3 PHP files in src, tests
		Excluded   fixtures    test data
		           src/C.php   generated
		PHP        8.0, `composer.json` names none
		Standard   perCs (PER Coding Style), the nearest to your code by the files a fix changes, 3 under psr12; `--use` names another
		Measured   your code departs from it in 1 decision over 3 files
		As written indentation.unit: tab   your code, all 3 files
		Checked    all 3 files: a fix changes no file

		Written    `dresscode.neon`
		Next       `dresscode check`      passes already

		XX, $out);

	$neon = (string) file_get_contents("$root/dresscode.neon");
	// the line endings init writes, whatever the checkout gave the heredoc
	Assert::same(str_replace("\r\n", "\n", <<<'XX'
		# made to measure by `dresscode init`; run it again to see what has moved since, it writes nothing over this file
		use: perCs   # PER Coding Style, of the standards the one a fix changes the fewest files of your code under

		paths: [src, tests]
		excludePaths:
			- fixtures    # test data
			- src/C.php   # generated
		fileExtensions: [php, phpt]

		# the namespaces of the scope declare no function and no constant, so an unqualified name in a namespace
		# is resolved for certain; one declared later is reported until it is listed
		nameResolution: certain

		indentation:
			unit: tab   # your code, all 3 files

		XX), $neon);
	Assert::notContains('fixRisky', $neon);

	// the round trip: what the run resolves the written file to is what was measured
	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same("\t", $resolved->indent);
	Assert::noError(
		fn() => Proposal::measure(
			ProjectFacts::detect($root),
			Scope::fromAutoload($root, Scope::Extensions),
			new InProcessProbeRunner($root),
		)
			->toDraft()
			->checkResolution($resolved),
	);

	// and the configuration is one a run takes, and passes, the generated file out
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$check = new Application($out, $out, cwd: $root)->run(['dresscode', 'check', '--no-cache', '--jobs', '1']);
	rewind($out);
	Assert::same(0, $check, (string) stream_get_contents($out));
});


test('a value init writes is shown as the file writes it, a map and an example among them', function () use ($tabbed) {
	$root = createProject('values-written', ['src/A.php' => $tabbed]);
	[$code, $out] = runInit(
		$root,
		['--use', 'perCs', '--set', 'blankLines.beforeStatement={return: 1}', '--set', "spacing.anonymousFunction='function(\$x)'"],
	);
	Assert::same(0, $code);
	Assert::contains('blankLines.beforeStatement: {return: 1}', $out);
	Assert::contains("spacing.anonymousFunction: 'function(\$x)'", $out);
});


test('init lists what the namespaces declare from every file', function () {
	$root = createProject('namespaced', [
		'src/helpers.php' => "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n}\n\nconst LIMIT = 1;\n",
		'src/polyfill.php' => "<?php\n\nnamespace App\\Compat;\n\nif (!function_exists('App\\Compat\\first')) {\n\tfunction first(): void\n\t{\n\t}\n}\n",
		'src/global.php' => "<?php\n\nfunction globalHelper(): void\n{\n}\n\ndefine('App\\\\BOOTED', true);\n",
		// a declaration on the line of the namespace, and a define() whose leading backslash reaches no name
		'src/oneline.php' => '<?php namespace App\One; function one() {} define(\'App\One\DEFINED\', 1); define(\'\App\One\LEADING\', 2);'
			. "\n",
		'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}',
		'composer.lock' => '{"packages": [{"name": "symfony/dependency-injection"}], "packages-dev": []}',
		'vendor/composer/installed.json' => '{"packages": [{"name": "symfony/dependency-injection", "version": "7.3.0", "install-path": "../symfony/dependency-injection"}]}',
	]);
	[$code, $out] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	Assert::match(<<<'XX'
		%A%use: perCs
		%A%
		# the namespaces of the scope declare these functions and constants and no others, so an unqualified name
		# in a namespace is resolved for certain; one declared later is reported until it is listed
		nameResolution: certain
		namespaces:
			functions:
				- App\Compat\first
				- App\helper
				- App\One\one
			constants:
				- App\BOOTED
				- App\LIMIT
				- App\One\DEFINED
		%A%
		XX, (string) file_get_contents("$root/dresscode.neon"));

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same('certain', $resolved->nameResolution);
	Assert::same(['dresscode/psr12', 'dresscode/perCs'], $resolved->use);
	Assert::same('the configuration', $resolved->namespacedFunctions['App\Compat\first']);
	Assert::same(
		'DressCode for symfony/dependency-injection',
		$resolved->namespacedFunctions['Symfony\Component\DependencyInjection\Loader\Configurator\service'],
	);
	Assert::true($resolved->findRule(DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class)?->isActive());
});


test('init turns the types on where the project has PHPStan, and says why', function () {
	$root = createProject('typed', [
		'src/a.php' => "<?php\n\nnamespace App;\n\nfunction a(): void\n{\n}\n",
		'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}',
		'composer.lock' => '{"packages": [], "packages-dev": [{"name": "phpstan/phpstan"}]}',
	]);
	[$code, $out] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	// the measuring goes without them, so the configuration written is checked with them once more
	Assert::contains(
		"Types      checked once more with the types of the code, which the counts above go without: the first fix rewrites no file and leaves 0 findings then\n",
		$out,
	);
	Assert::contains(
		"\ntypeAnalysis: phpstan   # phpstan/phpstan is installed, so the rules get the types of the code\n",
		(string) file_get_contents("$root/dresscode.neon"),
	);
	Assert::same('phpstan', Loader::loadFile("$root/dresscode.neon")->typeAnalysis);

	$root = createProject('untyped', ['src/a.php' => "<?php\n", 'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}']);
	runInit($root, ['--use', 'perCs']);
	Assert::notContains('typeAnalysis', (string) file_get_contents("$root/dresscode.neon"));
});


test('init --dry-run prints the configuration and writes nothing, and --formatter names the configuration of the formatter', function () {
	$root = createProject('dry-run', [
		'src/a.php' => "<?php\n\nfunction a(): void\n{\n}\n",
		'phpcs.xml' => '<?xml version="1.0"?><ruleset name="x"><rule ref="PSR12"/></ruleset>',
		'conf/pint.json' => '{"preset": "per"}',
	]);
	[$code, $out] = runInit($root, ['--dry-run', '--formatter', 'conf/pint.json']);
	Assert::same(0, $code);
	Assert::contains("Proposed   `dresscode.neon` below, nothing written, as `--dry-run` asks\n", $out);
	Assert::match("%A%\nuse: perCs   # %A%", $out);
	Assert::false(is_file("$root/dresscode.neon"));
});


test('more presets than one are written as a list', function () use ($tabbed) {
	$root = createProject('presets', ['src/A.php' => $tabbed]);
	[$code] = runInit($root, ['--use', 'psr12', '--use', 'perCs']);
	Assert::same(0, $code);
	Assert::contains("use:\n\t- psr12\n\t- perCs\n\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('a decision the code does not make clearly is not written as a value', function () {
	$root = createProject('undecided', [
		'src/a.php' => "<?php\n\nfunction a(): void\n{\n\techo 'a', 'b';\n}\n",
		'src/b.php' => "<?php\n\nfunction b(): void\n{\n    echo \"a\", \"b\";\n}\n",
	]);
	[$code, $out] = runInit($root, ['--use', 'nette']);
	Assert::same(0, $code);
	Assert::contains("Standard   nette (the Nette Coding Standard), as given\n", $out);
	Assert::contains("Unchecked  3 decisions left as your code has them (`keep`), each with the reason in the file\n", $out);

	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::contains("use: nette\n\n", $neon); // given, so the file says nothing of where it came from
	// half of the code one way and half another: unchecked, and the comment says what the code has
	Assert::match("%A%\tunit: keep %s% # not checked: your code has %a% in 50%, %a% in 50% of 2 files\n%A%", $neon);
	Assert::match("%A%\tquotes: keep %s% # not checked: your code has %a% in 50%, %a% in 50% of 4 places\n%A?%", $neon);
	Assert::notContains('fixRisky', $neon);

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::false($resolved->findRule(DressCode\Rules\Literals\StringQuotesRule::class)?->isActive());
	Assert::same("\t", $resolved->indent); // new code is indented by a tab where nothing decides it
});


test('the unit of the indentation is measured alone, whatever levels the other constructs step in by', function () {
	$switch = "<?php\n\nfunction f(\$a)\n{\n\tswitch (\$a) {\n\tcase 1:\n\t\treturn 1;\n\t}\n}\n";
	$root = createProject('unit', ['src/a.php' => $switch, 'src/b.php' => $switch]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("As written indentation.unit: tab   your code, all 2 files\n", $out);
});


test('the shape of the conditions is counted by condition, and the shapes the code has pass where none prevails', function () {
	$perLine = "if (\n\t\$a\n\t&& \$b\n) {\n}\n";
	$compact = "if (\$a\n\t&& \$b\n) {\n}\n";
	$attached = "if (\$a\n\t&& \$b) {\n}\n";
	$root = createProject('shapes', [
		'src/a.php' => "<?php\n" . str_repeat($perLine, 7),
		'src/b.php' => "<?php\n" . str_repeat($compact, 5),
	]);
	[$code] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::match(
		"%A%multiline:\n\tedges:\n\t\tcondition: [apart, closingApart] %s% # your code, apart in 58%, closingApart in 42% of 12 places\n%A?%",
		$neon,
	);

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same(['apart', 'closingApart'], $resolved->decisions['multiline.edges.condition']->value->getWords());

	// a shape at least 70 % of the conditions have is written as the shape
	$root = createProject('shape', ['src/a.php' => "<?php\n" . str_repeat($perLine, 2) . str_repeat($compact, 10)]);
	runInit($root, ['--use', 'perCs']);
	Assert::match(
		"%A%\t\tcondition: closingApart %s% # your code, 83% of 12 places\n%A?%",
		(string) file_get_contents("$root/dresscode.neon"),
	);

	// and so is every shape the rule knows, the bracket attached to the last part too
	$root = createProject('attached', ['src/a.php' => "<?php\n" . str_repeat($perLine, 2) . str_repeat($attached, 10)]);
	runInit($root, ['--use', 'perCs']);
	Assert::match(
		"%A%\t\tcondition: attached %s% # your code, 83% of 12 places\n%A?%",
		(string) file_get_contents("$root/dresscode.neon"),
	);
});


test('a decision the code holds to in all but a few files is written, unless its fix would change more than the budget', function () {
	$files = ['composer.json' => '{}'];
	foreach (range(1, 20) as $i) {
		$files[sprintf('src/f%02d.php', $i)] = $i <= 3 ? "<?php\n\$a = $i;\n" : "<?php\n\n\$a = $i;\n";
	}

	$root = createProject('budget', $files);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::match(
		'%A%Checked    all 20 files: a fix changes no file%A%1 more decision is left as your code has it, as the fixes together would change more than 2 files, the indentation, the line endings, the long lines and your answers aside (`--budget` raises the limit)%A%',
		$out,
	);
	Assert::contains(
		"\tafterOpeningTag: keep   # not checked: a fix would change 3 of 20 files\n",
		(string) file_get_contents("$root/dresscode.neon"),
	);

	// init over its own file has nothing to say has moved
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("Moved      nothing: `dresscode.neon` says what init measures\n", $out);

	// the budget given takes the three stragglers, and the fix aligns them
	unlink("$root/dresscode.neon");
	[$code, $out] = runInit($root, ['--budget', '3']);
	Assert::same(0, $code);
	Assert::contains('Next       `dresscode fix`        rewrites 3 files', $out);
	Assert::notContains('afterOpeningTag', (string) file_get_contents("$root/dresscode.neon"));

	// and so does the answer, which no budget leaves unchecked
	unlink("$root/dresscode.neon");
	[$code, $out] = runInit($root, ['--set', 'blankLines.afterOpeningTag=0']);
	Assert::same(0, $code);
	Assert::contains('rewrites 17 files', $out);
	Assert::contains("\tafterOpeningTag: 0   # your answer\n", (string) file_get_contents("$root/dresscode.neon"));

	// a decision nothing knows is no answer, told before the measuring
	unlink("$root/dresscode.neon");
	[$code, $out, $err] = runInit($root, ['--set', 'project.unknown=1']);
	Assert::same(3, $code);
	Assert::same('', $out);
	Assert::contains('`--set project.unknown=1` names no decision; `dresscode catalogue` lists them.', $err);
});


test(
	'a directory writing a decision of the file otherwise is told with the override that would keep it, none written',
	function () use ($tabbed) {
		$files = ['composer.json' => '{}'];
		foreach (range(1, 20) as $i) {
			$files["src/A$i.php"] = str_replace('class A', "class A$i", $tabbed);
		}

		foreach (range(1, 6) as $i) {
			$files["tests/T$i.php"] = str_replace(['class A', "\t"], ["class T$i", '    '], $tabbed);
		}

		[$code, $out] = runInit(createProject('region', $files), ['--use', 'perCs', '--dry-run']);
		Assert::same(0, $code);
		Assert::contains("indentation.unit: tab   your code, 77% of 26 files\n", $out);
		Assert::contains(
			"Differs    tests: indentation.unit 4 spaces in all 6 files; an override for `tests` would keep it, none is written\n",
			$out,
		);
		Assert::notContains('overrides', $out);
	},
);
