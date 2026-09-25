<?php declare(strict_types=1);

/**
 * init writes a configuration measured from the code: what it writes, the loader reads and resolves to the
 * values it measured, a configuration that exists is never overwritten, and a decision the code does not make
 * clearly enough is not written as a value.
 */

use DressCode\Config\{Loader, RunnerFactory};
use DressCode\Console\Application;
use DressCode\Measuring\{FileSample, Proposal};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * A project root of its own with the files given, relative path → content.
 * @param  array<string, string>  $files
 */
function createProject(string $name, array $files): string
{
	$root = createTempDir($name);
	foreach ($files as $path => $content) {
		@mkdir(dirname("$root/$path"), recursive: true); // @ directory may already exist
		file_put_contents("$root/$path", $content);
	}

	return $root;
}


/**
 * @param  list<string>  $args
 * @return array{int, string, string}
 */
function runInit(string $root, array $args = []): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = new Application($out, $err, cwd: $root)->run(['dresscode', 'init', ...$args]);
	rewind($out);
	rewind($err);
	return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}


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
		DRESS|CODE %a%
		Sample     3 of 4 files in src, tests, 1 generated left out
		Standard   perCs, not chosen by measure; the dry runs below count what each would change, not which is nearest, and `--use` writes another
		Indent     tab 100% of 3 files
		Quotes     single 86%, double 14% of 7 strings
		Conditions no conditions
		Namespaces no function and no constant declared, resolved for certain
		Dry run    perCs       1 of 3 sampled files would change, the one written
		           psr12       1 of 3
		           nette       3 of 3
		           symfony     3 of 3

		`dresscode.neon` written, made to measure.

		XX, $out);

	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::match(<<<'XX'
		# Written by dresscode init from 3 of the 4 files. %A%

		use: perCs  # not chosen by measure; the other complete standards are psr12, nette and symfony

		# the namespaces of the scope declare no function and no constant, so an unqualified name in a namespace
		# is resolved for certain; one declared later is reported until it is listed
		nameResolution: certain

		paths:
			- src
			- tests

		excludePaths:
			- fixtures

		fileExtensions:
			- php
			- phpt

		indentation:
			# Every line indented by the construct it continues, %A%
			# `2 spaces` (two spaces per level); `keep`
			unit: tab  # tab 100% of 3 files

		literals:
			# The quotes of a plain string, %A%
			quotes: single  # single 86%, double 14% of 7 strings

		XX, $neon);
	Assert::notContains('fixRisky', $neon);

	// the round trip: what the run resolves the written file to is what was measured
	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same("\t", $resolved->indent);
	Assert::same('single', $resolved->decisions['literals.quotes']->value->getWord());
	Assert::noError(fn() => Proposal::measure($root)->checkResolution($resolved));

	// and the configuration is one a run takes: the generated file is out, the one double quote is found
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$check = new Application($out, $out, cwd: $root)->run(['dresscode', 'check', '--no-cache', '--jobs', '1']);
	rewind($out);
	Assert::same(1, $check);
	Assert::match('%A%tests%a%c.phpt%A%literals.quotes%A%FOUND  1 violation%A%', (string) stream_get_contents($out));
});


test('a configuration that exists is not overwritten; the proposal goes to the output and the exit code says so', function () use ($tabbed) {
	$root = createProject('existing', ['src/A.php' => $tabbed, 'dresscode.php.dist' => "<?php\n"]);
	[$code, $out, $err] = runInit($root);
	Assert::same(3, $code);
	Assert::match("%A%`dresscode.php.dist` exists, so the proposal is printed and nothing is written.\n", $err);
	Assert::match("# Written by dresscode init %A%indentation:\n%A%\tunit: tab  # tab 100% of 1 files%A%", $out);
	Assert::same("<?php\n", file_get_contents("$root/dresscode.php.dist"));
	Assert::false(is_file("$root/dresscode.neon"));
});


test('init lists what the namespaces declare from every file, and proposes the preset of an installed framework', function () {
	$root = createProject('namespaced', [
		'src/helpers.php' => "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n}\n\nconst LIMIT = 1;\n",
		'src/polyfill.php' => "<?php\n\nnamespace App\\Compat;\n\nif (!function_exists('App\\Compat\\first')) {\n\tfunction first(): void\n\t{\n\t}\n}\n",
		'src/global.php' => "<?php\n\nfunction globalHelper(): void\n{\n}\n\ndefine('App\\\\BOOTED', true);\n",
		// a declaration on the line of the namespace, and a define() whose leading backslash reaches no name
		'src/oneline.php' => '<?php namespace App\One; function one() {} define(\'App\One\DEFINED\', 1); define(\'\App\One\LEADING\', 2);' . "\n",
		'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}',
		'composer.lock' => '{"packages": [{"name": "symfony/dependency-injection"}], "packages-dev": []}',
		'vendor/composer/installed.json' => '{"packages": [{"name": "symfony/dependency-injection", "version": "7.3.0", "install-path": "../symfony/dependency-injection"}]}',
	]);
	[$code, $out] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	Assert::contains('Namespaces 3 functions and 3 constants declared, resolved for certain', $out);
	Assert::match(<<<'XX'
		%A%use: perCs
		%A?%
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
	Assert::same('DressCode for symfony/dependency-injection', $resolved->namespacedFunctions['Symfony\Component\DependencyInjection\Loader\Configurator\service']);
	Assert::true($resolved->findRule(DressCode\Rules\Namespaces\NoUnlistedNamespacedDeclarationsRule::class)?->isActive());
});


test('init turns the types on where the project has PHPStan, and says why', function () {
	$root = createProject('typed', [
		'src/a.php' => "<?php\n\nnamespace App;\n\nfunction a(): void\n{\n}\n",
		'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}',
		'composer.lock' => '{"packages": [], "packages-dev": [{"name": "phpstan/phpstan"}]}',
	]);
	[$code] = runInit($root, ['--use', 'perCs']);
	Assert::same(0, $code);
	Assert::match(<<<'XX'
		%A%
		# phpstan/phpstan is installed, so the rules get the types of the code: a fix the type of a value decides
		# is made where the types tell it is safe, and the rules that need the types run
		typeAnalysis: phpstan
		%A%
		XX, (string) file_get_contents("$root/dresscode.neon"));
	Assert::same('phpstan', Loader::loadFile("$root/dresscode.neon")->typeAnalysis);

	$root = createProject('untyped', ['src/a.php' => "<?php\n", 'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}']);
	runInit($root, ['--use', 'perCs']);
	Assert::notContains('types:', (string) file_get_contents("$root/dresscode.neon"));
});


test('a file that does not parse leaves what the namespaces declare unknown, so init writes no certain resolution', function () {
	$root = createProject('unparsed', [
		'src/helpers.php' => "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n}\n",
		'src/broken.php' => "<?php\n\nnamespace App;\n\nfunction (\n",
	]);
	[, $out] = runInit($root);
	Assert::contains('Namespaces 1 function and no constant declared, not resolved for certain, src/broken.php does not parse', $out);
	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::contains('# src/broken.php does not parse, so the namespaces may declare more than is listed here', $neon);
	Assert::notContains("\nnameResolution:", $neon);
	Assert::contains("namespaces:\n\tfunctions:\n\t\t- App\\helper\n", $neon);
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
	// a standard that was given is the only one priced, and the others are not even named
	Assert::contains("Standard   nette, as given\n", $out);
	Assert::match("%A%Dry run    %d% of 2 sampled files would change\n%A%", $out);
	Assert::notContains('symfony', $out);

	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::contains("use: nette\n\n", $neon); // given, so the file does not say it was not measured
	// the indentation has no value to fall back to, so the standard keeps it and the comment says why
	Assert::contains("# indentation.unit: %s; no value reaches 70%, so the standard decides\n", str_replace(['tab 50%, 4 50% of 2 files', '4 50%, tab 50% of 2 files'], '%s', $neon));
	Assert::notContains("\nindentation:", $neon);
	// quotes have no tolerance, so they are kept rather than set to a value half of the strings disagree with
	Assert::match("%A%\tquotes: keep  # %a% 50%, %a% 50% of 4 strings\n%A?%", $neon);
	Assert::notContains('fixRisky', $neon);

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::false($resolved->findRule(DressCode\Rules\Literals\StringQuotesRule::class)?->isActive());
	Assert::same("\t", $resolved->indent); // the tab of dresscode/nette
});


test('the unit of the indentation is measured alone, whatever levels the other constructs step in by', function () {
	$switch = "<?php\n\nfunction f(\$a)\n{\n\tswitch (\$a) {\n\tcase 1:\n\t\treturn 1;\n\t}\n}\n";
	$root = createProject('unit', ['src/a.php' => $switch, 'src/b.php' => $switch]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("Indent     tab 100% of 2 files\n", $out);
});


test('the shape of the conditions is counted by condition, and both shapes pass where neither prevails', function () {
	$perLine = "if (\n\t\$a\n\t&& \$b\n) {\n}\n";
	$compact = "if (\$a\n\t&& \$b\n) {\n}\n";
	$neither = "if (\$a\n\t&& \$b) {\n}\n";
	$root = createProject('shapes', [
		'src/a.php' => "<?php\n$perLine$perLine$neither",
		'src/b.php' => "<?php\n$compact",
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("Conditions perLine 50%, compact 25% of 4 conditions, 1 in none of them\n", $out);
	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::match("%A%multiline:\n\t# The shape of a condition %A%\n\tcondition: [perLine, compact]  # perLine 50%, compact 25% of 4 conditions, 1 in none of them\n%A?%", $neon);

	$factory = new RunnerFactory;
	$resolved = $factory->resolve(Loader::loadFile("$root/dresscode.neon"), $root)->resolvedConfig;
	Assert::same(['perLine', 'compact'], $resolved->decisions['multiline.condition']->value->getWords());

	// a shape at least 70 % of the conditions have is written as the shape
	$root = createProject('shape', ['src/a.php' => "<?php\n$perLine$perLine$perLine$compact"]);
	runInit($root);
	Assert::contains("\tcondition: perLine  # perLine 75%, compact 25% of 4 conditions\n", (string) file_get_contents("$root/dresscode.neon"));

	// conditions mostly in no shape the rule knows are kept, or the standard would write them all again
	$root = createProject('no-shape', ['src/a.php' => "<?php\n$perLine$neither$neither$neither"]);
	runInit($root);
	Assert::contains("\tcondition: keep  # perLine 25% of 4 conditions, 3 in none of them\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('the scope is what the autoload names and the conventional directories beside it', function () {
	$php = "<?php\n";
	$composer = fn(array $autoload) => json_encode($autoload, JSON_THROW_ON_ERROR);

	// psr-4 with a prefix on several paths and one that is not there, a test suite only the convention knows
	$root = createProject('scope-psr4', [
		'composer.json' => $composer([
			'autoload' => ['psr-4' => ['App\\' => 'ModuleFront', 'App\\Api\\' => ['ModuleApi/', 'gone']]],
			'autoload-dev' => ['psr-4' => ['App\\Tests\\' => 'test/']],
		]),
		'ModuleFront/a.php' => $php,
		'ModuleApi/a.php' => $php,
		'test/a.php' => $php,
		'www.admin/index.php' => $php,
		'cron/a.php' => $php,
		'assets/a.php' => $php,
	]);
	Assert::same(['ModuleApi', 'ModuleFront', 'cron', 'test', 'www.admin'], Proposal::findPaths($root));

	// a classmap names directories and single files, and the files section names no scope at all
	$root = createProject('scope-classmap', [
		'composer.json' => $composer(['autoload' => ['classmap' => ['src/', 'src/helpers.php'], 'files' => ['bootstrap.php']]]),
		'src/a.php' => $php,
		'src/helpers.php' => $php,
		'bootstrap.php' => $php,
		'tests/a.php' => $php,
	]);
	Assert::same(['src', 'tests'], Proposal::findPaths($root));

	// a path inside another one is already in the scope, whichever of the two named it
	$root = createProject('scope-nested', [
		'composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => 'ModuleFront/', 'App\\Sub\\' => 'ModuleFront/Sub']]]),
		'ModuleFront/a.php' => $php,
		'ModuleFront/Sub/a.php' => $php,
	]);
	Assert::same(['ModuleFront'], Proposal::findPaths($root));

	$root = createProject('scope-app', [
		'composer.json' => $composer(['autoload' => ['psr-0' => ['' => 'app/Model']]]),
		'app/Model/a.php' => $php,
		'app/bootstrap.php' => $php,
	]);
	Assert::same(['app'], Proposal::findPaths($root));

	// a dot segment names the same directory as the path without it, and only resolved do the two compare as one
	$root = createProject('scope-dots', [
		'composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => './src', 'App\\Api\\' => 'app/../ModuleApi']]]),
		'src/a.php' => $php,
		'ModuleApi/a.php' => $php,
	]);
	Assert::same(['ModuleApi', 'src'], Proposal::findPaths($root));

	// an autoload pointing at the root itself is the whole scope
	$root = createProject('scope-root', [
		'composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => '']]]),
		'src/a.php' => $php,
	]);
	Assert::same(['.'], Proposal::findPaths($root));

	// the composer.json of a directory above names paths of another project, which are none of this scope
	$root = createProject('scope-above/package', [
		'../composer.json' => $composer(['autoload' => ['psr-4' => ['App\\' => 'src']]]),
		'../src/a.php' => $php, // the src of the project above, which exists and is still none of this scope
		'src/a.php' => $php,
	]);
	Assert::same(['src'], Proposal::findPaths($root));

	// without a composer.json the convention is the whole answer
	$root = createProject('scope-conventional', ['src/a.php' => $php, 'tests/a.php' => $php, 'storage/a.php' => $php]);
	Assert::same(['src', 'tests'], Proposal::findPaths($root));
});


test('without the usual directories the root itself is the scope', function () use ($tabbed) {
	$root = createProject('flat', ['A.php' => $tabbed]);
	[$code] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("paths:\n\t- .\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('the sample is every k-th file, the same for the same tree, without a file too large or generated', function () {
	$files = [];
	foreach (range(1, 400) as $i) {
		$files[sprintf('src/f%03d.php', $i)] = "<?php\n";
	}

	$files['src/f007.php'] = "<?php\n" . str_repeat('// x', 30_000);
	$files['src/f013.php'] = "<?php\n\n/**\n * This file is auto-generated, do not edit it.\n */\n";
	$root = createProject('sample', $files);
	$scope = array_keys($files);
	sort($scope, SORT_STRING);

	$sample = FileSample::pick($root, $scope);
	Assert::same(198, count($sample->files)); // every second of the 400, less the two
	Assert::same(1, $sample->oversized);
	Assert::same(1, $sample->generated);
	Assert::same('src/f001.php', $sample->files[0]);
	Assert::same('src/f003.php', $sample->files[1]);
	Assert::notContains('src/f007.php', $sample->files);
	Assert::notContains('src/f013.php', $sample->files);
	Assert::same($sample->files, FileSample::pick($root, $scope)->files);

	// the budget of bytes is the cost of the measure, and it holds whatever the count allows
	$files = [];
	foreach (range(1, 30) as $i) {
		$files[sprintf('src/g%02d.php', $i)] = "<?php\n" . str_repeat('x', 90_000);
	}

	$root = createProject('sample-bytes', $files);
	$sample = FileSample::pick($root, array_keys($files));
	Assert::same(22, count($sample->files)); // 22 * 90 kB is the last that fits in 2 MB
	Assert::same(8, $sample->oversized);
});
