<?php declare(strict_types=1);

/**
 * init writes a configuration measured from the code: what it writes, the loader reads and resolves to the
 * values it measured, a configuration that exists is never overwritten, and a decision the code does not make
 * clearly enough is not written as a value.
 */

use DressCode\Config\Loader;
use DressCode\Config\Proposal;
use DressCode\Config\RunnerFactory;
use DressCode\Config\Survey;
use DressCode\Console\Application;
use Tester\Assert;
use Tester\Helpers;


require __DIR__ . '/../../bootstrap.php';


/**
 * A project root of its own with the files given, relative path → content.
 * @param  array<string, string>  $files
 */
function createProject(string $name, array $files): string
{
	$root = str_replace('\\', '/', __DIR__ . '/../../temp/init/' . $name);
	@mkdir($root, recursive: true); // @ - may exist
	Helpers::purge($root);
	foreach ($files as $path => $content) {
		@mkdir(dirname("$root/$path"), recursive: true); // @ - may exist
		file_put_contents("$root/$path", $content);
	}

	return (string) realpath($root);
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
		'tests/c.phpt' => "<?php\n\nfunction c(): void\n{\n\techo \"plain\";\n}\n",
		'tests/fixtures/generated.php' => "<?php\n    \$x = \"a\";\n",
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::match(<<<'XX'
		DRESS|CODE %a%
		Sample     3 of 3 files in src, tests
		Standard   per, not measured; the others are psr12, nette and symfony
		Indent     tab 100% of 3 files
		Quotes     single 86%, double 14% of 7 strings
		Dry run    %d% of 3 sampled files would change

		dresscode.neon written.

		XX, $out);

	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::match(<<<'XX'
		# Written by dresscode init from 3 of the 3 files. %A%

		presets:
			- per
			# not measured; the other complete standards are psr12, nette and symfony

		indent: tab  # tab 100% of 3 files

		rules:
			string-quotes: single  # single 86%, double 14% of 7 strings

		paths:
			- src
			- tests

		excludePaths:
			- fixtures

		fileExtensions:
			- php
			- phpt

		XX, $neon);
	Assert::notContains('risky', $neon);

	// the round trip: what the run resolves the written file to is what was measured
	$factory = new RunnerFactory;
	$factory->createRunner(Loader::loadFile("$root/dresscode.neon"), $root, cache: false);
	$resolved = $factory->getResolvedConfig();
	Assert::same("\t", $resolved->indent);
	Assert::same(['quotes' => 'single'], $resolved->getRule('dresscode/string-quotes')?->options);
	Assert::noError(fn() => Proposal::measure($root)->checkResolution($resolved));

	// and the configuration is one a run takes: the generated file is out, the one double quote is found
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$check = new Application($out, $out, cwd: $root)->run(['dresscode', 'check', '--no-cache', '--jobs', '1']);
	rewind($out);
	Assert::same(1, $check);
	Assert::match('%A%tests%a%c.phpt%A%string-quotes%A%FOUND  1 violation%A%', (string) stream_get_contents($out));
});


test('a configuration that exists is not overwritten; the proposal goes to the output and the exit code says so', function () use ($tabbed) {
	$root = createProject('existing', ['src/A.php' => $tabbed, 'dresscode.php.dist' => "<?php\n"]);
	[$code, $out, $err] = runInit($root);
	Assert::same(2, $code);
	Assert::match("%A%dresscode.php.dist exists, so the proposal is printed and nothing is written.\n", $err);
	Assert::match('# Written by dresscode init %A%indent: tab  # tab 100% of 1 files%A%', $out);
	Assert::same("<?php\n", file_get_contents("$root/dresscode.php.dist"));
	Assert::false(is_file("$root/dresscode.neon"));
});


test('a decision the code does not make clearly is not written as a value', function () {
	$root = createProject('undecided', [
		'src/a.php' => "<?php\n\nfunction a(): void\n{\n\techo 'a', 'b';\n}\n",
		'src/b.php' => "<?php\n\nfunction b(): void\n{\n    echo \"a\", \"b\";\n}\n",
	]);
	[$code] = runInit($root, ['--preset', 'nette']);
	Assert::same(0, $code);
	$neon = (string) file_get_contents("$root/dresscode.neon");
	Assert::contains("presets:\n\t- nette\n\n", $neon); // given, so the file does not say it was not measured
	// the indentation has no value to fall back to, so the standard keeps it and the comment says why
	Assert::contains("# indent: %s; no value reaches 70%, so the standard decides\n", str_replace(['tab 50%, 4 50% of 2 files', '4 50%, tab 50% of 2 files'], '%s', $neon));
	Assert::notContains("\nindent:", $neon);
	// quotes have no tolerance, so the rule is kept rather than set to a value half of the strings disagree with
	Assert::match("%A%\tstring-quotes: keep  # %a% 50%, %a% 50% of 4 strings\n%A%", $neon);
	Assert::notContains('risky', $neon);

	$factory = new RunnerFactory;
	$factory->createRunner(Loader::loadFile("$root/dresscode.neon"), $root, cache: false);
	Assert::false($factory->getResolvedConfig()->getRule('dresscode/string-quotes')?->isActive());
	Assert::same("\t", $factory->getResolvedConfig()->indent); // the tab of dresscode/nette
});


test('without the usual directories the root itself is the scope', function () use ($tabbed) {
	$root = createProject('flat', ['A.php' => $tabbed]);
	[$code] = runInit($root);
	Assert::same(0, $code);
	Assert::contains("paths:\n\t- .\n", (string) file_get_contents("$root/dresscode.neon"));
});


test('the sample is every k-th file, at most MaxFiles, the same for the same tree', function () {
	$files = array_map(fn(int $i) => sprintf('src/f%04d.php', $i), range(1, 700));
	$sample = Survey::pick($files);
	Assert::same(234, count($sample));
	Assert::same('src/f0001.php', $sample[0]);
	Assert::same('src/f0004.php', $sample[1]);
	Assert::same($sample, Survey::pick($files));
	Assert::same(['a.php', 'b.php'], Survey::pick(['a.php', 'b.php']));
});
