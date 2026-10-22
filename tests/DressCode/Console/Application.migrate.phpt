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
	Assert::same(1, $code);
	$data = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(
		[DressCode\Console\Commands\MigrateCommand::Version, null, ['dresscode/psr12']],
		[$data['version'], $data['written'], $data['presets']],
	);
	Assert::match('<?php declare(strict_types=1);%A%', $data['configuration']);
	Assert::notSame([], $data['warnings']);
	Assert::false(is_file("$root/migrated.php"));

	[$code, $out, $err] = runApp($root, ['migrate', 'config', "$root/phpcs.xml", '--output', "$root/migrated.php"]);
	Assert::same([1, ''], [$code, $out]);
	Assert::same(
		"<?php declare(strict_types=1);\n\n"
		. "use DressCode\\Config;\n\n"
		. "return new Config(\n"
		. "\tuse: ['psr12'],\n"
		. "\tdecisions: [\n"
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
		. "\t],\n"
		. ");\n",
		file_get_contents("$root/migrated.php"),
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
	[$code] = runApp($root, ['migrate', 'config', 'fixer.php', '-o', 'dresscode-fixer.php']); // relative to the working directory of the run
	Assert::same(0, $code);
	Assert::contains("\t\t'spacing' => [\n\t\t\t'cast' => '(int)\$x',\n\t\t],\n", (string) file_get_contents("$root/dresscode-fixer.php"));

	// the file is written in the format of its extension, as a configuration the run reads
	[$code, $out] = runApp($dir, ['migrate', 'config', "$root/phpcs.xml"]);
	Assert::same([1, ''], [$code, $out]);
	$neon = (string) file_get_contents("$dir/dresscode.neon");
	Assert::match("use: psr12\n\nblankLines:\n%A%\n\nmultiline:\n\ttrailingComma:\n\t\tarray: required\n%A%", $neon);
	// what a foreign rule left untranslated stays in the file at a decision it gave
	Assert::contains('aroundDeclarations: 1       # `Squiz.WhiteSpace.FunctionSpacing` leaves the blank lines around classes alone', $neon);
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
