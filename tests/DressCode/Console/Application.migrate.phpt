<?php declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/Application.helpers.php';


$root = createConsoleProject();


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
	// a tool reads the translation as data, a dry run writing nothing
	[$code, $out] = runApp(
		$root,
		['migrate', 'config', "$root/phpcs.xml", '--output', "$root/migrated.php", '--dry-run', '--format', 'json'],
	);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(
		[DressCode\Console\Commands\MigrateCommand::Version, null, ['dresscode/psr12']],
		[$data['version'], $data['written'], $data['presets']],
	);
	Assert::match('<?php declare(strict_types=1);%A%', $data['configuration']);
	Assert::same(['whole' => 2, 'off' => 0, 'difference' => 1, 'request' => 0, 'uncovered' => 1, 'general' => 0], $data['counts']);
	Assert::same(
		[
			'kind' => 'uncovered',
			'rules' => ['Squiz.Nonsense.DoesNotExist'],
			'message' => 'No DressCode rule covers `Squiz.Nonsense.DoesNotExist`.',
		],
		$data['notes'][1] ?? null,
	);
	Assert::false(is_file("$root/migrated.php"));

	[$code, $out, $err] = runApp($root, ['migrate', 'config', "$root/phpcs.xml", '--output', "$root/migrated.php"]);
	Assert::same([0, ''], [$code, $out]); // what was not carried over is said, and no failure
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "// Translated from `phpcs.xml` of PHP_CodeSniffer\n"
		. '// by `dresscode migrate config` of DressCode ' . DressCode\Console\Application::Version . ".\n"
		. "// What could not be carried over is at the end of this file.\n\n"
		. "use DressCode\\Config;\n\n"
		. "return new Config(\n"
		. "\tuse: ['psr12'],\n"
		. "\tdecisions: [\n"
		. "\t\t'blankLines' => [\n" // the blank lines inside the braces of a class PSR-12 gives already
		. "\t\t\t'aroundMethods' => 1,\n"
		. "\t\t\t'aroundInterfaceMethods' => 1,\n"
		. "\t\t\t'aroundDeclarations' => 1,\n"
		. "\t\t],\n"
		. "\t\t'multiline' => [\n"
		. "\t\t\t'trailingComma' => [\n"
		. "\t\t\t\t'array' => 'required',\n"
		. "\t\t\t\t'argument' => 'required',\n"
		. "\t\t\t],\n"
		. "\t\t],\n"
		. "\t],\n"
		. ");\n"
		. "\n"
		. "// Left behind by the translation. Delete this block and the comment at the top once it is dealt with.\n"
		. "//\n"
		. "// Works a little differently\n"
		. "//   ~ `Squiz.WhiteSpace.FunctionSpacing` leaves the blank lines around classes alone, while\n"
		. "//       `blankLines` sets them together with those around functions.\n"
		. "//\n"
		. "// No DressCode rule, so nothing checks these now\n"
		. "//   ✗ Squiz.Nonsense.DoesNotExist\n",
		file_get_contents("$root/migrated.php"),
	);
	Assert::match(
		"4 rules in your `phpcs.xml`, on the preset `psr12`:\n" // nothing above it to set it apart from
		. "2  carried over exactly\n"
		. "1  carried over, but DressCode's result may differ a little\n"
		. "1  dropped, DressCode has no such rule, so nothing checks them now\n"
		. "\n"
		. "The details are at the end of `%a%`.\n"
		. "Next, `dresscode check` shows what the configuration finds in your code.\n"
		. "\n"
		. "WRITTEN  %a%\n",
		$err,
	);

	file_put_contents(
		"$root/fixer.php",
		"<?php\nreturn new class {\n\tpublic function getRules(): array\n\t{\n\t\treturn ['cast_spaces' => ['space' => 'none']];\n\t}\n};\n",
	);
	[$code] = runApp($root, ['migrate', 'config', 'fixer.php', '-o', 'dresscode-fixer.php']); // relative to the working directory of the run
	Assert::same(0, $code);
	Assert::contains("\t\t'spacing' => [\n\t\t\t'cast' => '(int)\$x',\n\t\t],\n", (string) file_get_contents("$root/dresscode-fixer.php"));

	// the file is written in the format of its extension, as a configuration the run reads
	[$code, $out] = runApp($dir, ['migrate', 'config', "$root/phpcs.xml"]);
	Assert::same([0, ''], [$code, $out]);
	$neon = (string) file_get_contents("$dir/dresscode.neon");
	Assert::match(
		"# Translated from `phpcs.xml` of PHP_CodeSniffer\n# by `dresscode migrate config` of DressCode %a%.\n# What could not be carried over is at the end of this file.\n"
		. "use: psr12\n\nblankLines:\n%A%\n\nmultiline:\n\ttrailingComma:\n\t\tarray: required\n%A%",
		$neon,
	);
	// what was not carried over closes the file, no decision carrying a comment of it
	Assert::match(
		"%A%\n\n# Left behind by the translation. Delete this block and the comment at the top once it is dealt with.\n#\n# Works a little differently\n#   ~ `Squiz.WhiteSpace.FunctionSpacing` %A%\n#   ✗ Squiz.Nonsense.DoesNotExist\n",
		$neon,
	);
	Assert::contains("aroundDeclarations: 1\n", $neon);
	Assert::same(
		'required',
		DressCode\Config\NeonReader::read("$dir/dresscode.neon")->decisions['multiline']['trailingComma']['array'] ?? null,
	);

	[$code, , $err] = runApp($dir, ['migrate', 'config', "$root/phpcs.xml"]);
	Assert::same(3, $code);
	Assert::contains('exists already', $err);
	// a configuration of the other format collides too, which a run would refuse beside it
	[$code, , $err] = runApp($dir, ['migrate', 'config', "$root/phpcs.xml", '-o', 'dresscode.php']);
	Assert::same(3, $code);
	Assert::contains('The configuration `dresscode.neon` exists already', $err);
	Assert::false(is_file("$dir/dresscode.php"));
	[$code, , $err] = runApp($root, ['migrate', 'config', "$root/phpcs.xml", '-o', "$dir/dresscode.yaml"]);
	Assert::same(3, $code);
	Assert::contains('its extension is `.neon` or `.php`', $err);
});
