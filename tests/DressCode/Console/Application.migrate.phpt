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
		. "\tpaths: ['src'],\n"
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
		. "use: psr12\n\npaths: [.]\n\nblankLines:\n%A%\n\nmultiline:\n\ttrailingComma:\n\t\tarray: required\n%A%",
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
	// the paths it names are relative to the project, which a configuration elsewhere would read relative to itself
	[$code, , $err] = runApp($root, ['migrate', 'config', "$root/phpcs.xml", '-o', "$dir/other.neon"]);
	Assert::same(3, $code);
	Assert::contains('would lie outside the root of the project', $err);
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
	Assert::match("%A%use: perCs\n%A%cast: '(int)\$x'%A%", (string) file_get_contents("$project/dresscode.neon"));

	// a dry run prints what it would write, over a file that exists too, and writes nothing
	$written = (string) file_get_contents("$project/dresscode.neon");
	file_put_contents("$project/dresscode.neon", '');
	[$code, $out, $err] = runApp($project, ['migrate', 'config', '--dry-run']);
	Assert::same([0, $written], [$code, $out]);
	Assert::same(
		"Found `pint.json` of Laravel Pint.\n\n1 rule in your `pint.json`, on the preset `perCs`:\n1  carried over exactly\n\nNothing written, as `--dry-run` asks.\n",
		$err,
	);
	Assert::same('', file_get_contents("$project/dresscode.neon"));

	// a preset alone translates no rule, which the headline must not count as nothing translated
	$project2 = createTempDir('import-preset');
	file_put_contents("$project2/pint.json", '{"preset": "per"}');
	[$code, , $err] = runApp($project2, ['migrate', 'config']);
	Assert::same(0, $code);
	Assert::match(
		"Found `pint.json` of Laravel Pint.\n\nNext, `dresscode check` %a%\n\nWRITTEN  dresscode.neon\n",
		$err,
	);

	$project = createTempDir('import-fixer');
	file_put_contents(
		"$project/.php-cs-fixer.dist.php",
		"<?php\nfile_put_contents(__DIR__ . '/ran', '');\nreturn ['cast_spaces' => true];\n",
	);
	file_put_contents("$project/composer.json", '{"scripts": {"cs": "php-cs-fixer fix --config=.php-cs-fixer.dist.php"}}');
	[$code, , $err] = runApp($project, ['migrate', 'config']);
	Assert::same(3, $code);
	Assert::contains(
		'`.php-cs-fixer.dist.php` is PHP, so reading it means running it, and it is read only when named: `dresscode migrate config .php-cs-fixer.dist.php`.',
		$err,
	);
	Assert::false(is_file("$project/ran"));

	[$code, $out, $err] = runApp($project, ['migrate', 'config'], "n\n", interactive: true);
	Assert::same(0, $code);
	Assert::contains('Found `.php-cs-fixer.dist.php` of PHP CS Fixer, run by `composer cs`.', $err);
	Assert::contains('Run it? `.php-cs-fixer.dist.php` is PHP, so reading it means running it. [Y/n]', $out); // the question, then why it is asked
	Assert::contains('Nothing written.', $err);
	Assert::false(is_file("$project/ran"));
	Assert::false(is_file("$project/dresscode.neon"));

	[$code] = runApp($project, ['migrate', 'config'], "\n", interactive: true);
	Assert::same(0, $code);
	Assert::true(is_file("$project/ran"));
	Assert::true(is_file("$project/dresscode.neon"));
});


test('migrate config counts the rules by what became of them, and a dry run points at the configuration it printed', function () {
	$project = createTempDir('import-counts');
	file_put_contents("$project/pint.json", json_encode(['preset' => 'per', 'rules' => [
		'cast_spaces' => true,
		'phpdoc_trim' => false,
		'void_return' => true,
		'no_spaces_around_offset' => ['positions' => ['inside']],
		'no_such_fixer' => true,
	]]));
	[$code, $out, $err] = runApp($project, ['migrate', 'config', '--dry-run']);
	Assert::same(0, $code);
	Assert::same(
		"Found `pint.json` of Laravel Pint.\n"
		. "\n"
		. "5 rules in your `pint.json`, on the preset `perCs`:\n"
		. "2  carried over exactly (including the 1 you have turned off)\n"
		. "1  carried over, but DressCode's result may differ a little\n"
		. "1  you need to finish by hand: `no_spaces_around_offset`\n"
		. "1  dropped, DressCode has no such rule, so nothing checks them now\n"
		. "\n"
		. "The details are at the end of the printed configuration.\n"
		. "\n"
		. "Nothing written, as `--dry-run` asks.\n",
		$err,
	);
	Assert::match("%A%# Waiting for you\n#   ! `no_spaces_around_offset`: %A%", $out);
});


test('migrate config asks which configuration a project with several runs, and elsewhere takes the first and says so', function () {
	$project = createTempDir('import-several');
	file_put_contents("$project/phpcs.xml", '<?xml version="1.0"?><ruleset name="x"><rule ref="PSR12"/></ruleset>');
	file_put_contents("$project/pint.json", '{"preset": "per"}');
	[$code, , $err] = runApp($project, ['migrate', 'config']);
	Assert::same(0, $code);
	Assert::contains(
		'The project has also `pint.json` of Laravel Pint; the first is taken, and `dresscode migrate config <file>` translates another.',
		$err,
	);

	unlink("$project/dresscode.neon");
	[$code, $out, $err] = runApp($project, ['migrate', 'config'], "2\n", interactive: true);
	Assert::same(0, $code);
	Assert::contains("  [1] `phpcs.xml` of PHP_CodeSniffer\n  [2] `pint.json` of Laravel Pint\nWhich one does it run? [1/2]", $out);
	Assert::contains('Found `pint.json` of Laravel Pint.', $err);
});


test('migrate config writes the scope, and says what it leaves out of it', function () {
	$project = createTempDir('import-scope');
	foreach (['src/A.php', 'src/drivers/B.php', 'tests/C.php'] as $file) {
		@mkdir(dirname("$project/$file"), recursive: true); // @ directory may already exist
		file_put_contents("$project/$file", "<?php\n");
	}

	file_put_contents("$project/phpcs.xml", <<<'XX'
		<?xml version="1.0"?>
		<ruleset name="Demo">
			<rule ref="PSR12"/>
		</ruleset>
		XX);
	[$code] = runApp($project, ['migrate', 'config']);
	Assert::same(0, $code);
	$neon = (string) file_get_contents("$project/dresscode.neon");
	Assert::contains("use: psr12\n\npaths: [src, tests]\n", $neon);

	// the data of tests are left out, which the file says is a change
	unlink("$project/dresscode.neon");
	@mkdir("$project/src/fixtures"); // @ directory may already exist
	file_put_contents("$project/src/fixtures/D.php", "<?php\n");
	[$code] = runApp($project, ['migrate', 'config']);
	Assert::same(0, $code);
	Assert::match(
		"%A%# Waiting for you\n#   ! `fixtures` is excluded, test data; drop it from `excludePaths` to check it.\n",
		(string) file_get_contents("$project/dresscode.neon"),
	);
});


test('migrate suppressions rewrites phpcs comments to the dresscode form', function () {
	$root = createConsoleProject();
	file_put_contents(
		"$root/src/s.php",
		"<?php\n// phpcs:ignoreFile\n/**\n * @phpcsSuppress Squiz.WhiteSpace.SuperfluousWhitespace\n */\nclass S\n{\n\t// phpcs:ignore Squiz.WhiteSpace.SuperfluousWhitespace, Generic.Metrics.CyclomaticComplexity -- why\n\tpublic \$a; // phpcs:disable Squiz.WhiteSpace.SuperfluousWhitespace\n\t// phpcs:enable\n}\n",
	);
	// a dry run shows the rewrites and writes nothing
	$original = (string) file_get_contents("$root/src/s.php");
	[$code, $out] = runApp($root, ['migrate', 'suppressions', 'src/s.php', '--dry-run']);
	Assert::same(0, $code);
	Assert::match(
		"%A%-// phpcs:ignoreFile\n+// dresscode:ignoreFile\n%A%Of the 5 comments to rewrite:\n%A%Would rewrite 5 comments in 1 file, writing nothing.\n",
		$out,
	);
	Assert::same($original, file_get_contents("$root/src/s.php"));
	[$code, $out] = runApp($root, ['migrate', 'suppressions', 'src/s.php', '--dry-run', '--format', 'json']);
	Assert::same(0, $code);
	$data = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same([5, 1, false], [$data['migrated'], $data['files'], $data['written']]);
	Assert::match("%A%+// dresscode:ignoreFile\n%A%", $data['diffs']['src/s.php']);
	Assert::same(['Generic.Metrics.CyclomaticComplexity' => ['src/s.php:8']], $data['unknown']);
	Assert::same($original, file_get_contents("$root/src/s.php"));

	// a name nothing covers and an ignore whose scope grows are said, and neither is a failure
	[$code, $out, $err] = runApp($root, ['migrate', 'suppressions', 'src/s.php']);
	Assert::same('', $err);
	Assert::same(0, $code);
	Assert::same(
		"Of the 5 comments rewritten:\n"
		. "1  names a rule DressCode does not know, which silences nothing: `Generic.Metrics.CyclomaticComplexity` (src/s.php:8)\n"
		. "1  now silences the whole statement below it, not just the next line, so it may hide more than you meant (src/s.php:8)\n"
		. "\n"
		. "Next, replace or remove the unknown names and check what the others hide; then `dresscode check` shows the violations left.\n"
		. "\n"
		. "REWRITTEN  5 comments in 1 file\n",
		$out,
	);
	Assert::same(
		"<?php\n// dresscode:ignoreFile\n/**\n * @phpcsSuppress file.trailingWhitespace\n */\nclass S\n{\n\t// dresscode:ignore file.trailingWhitespace, Generic.Metrics.CyclomaticComplexity -- why\n\tpublic \$a; // dresscode:disable file.trailingWhitespace\n\t// dresscode:enable\n}\n",
		file_get_contents("$root/src/s.php"),
	);
	[$code, $out] = runApp($root, ['migrate', 'suppressions', 'src/s.php']);
	Assert::same([0, "OK  no phpcs comments to rewrite\n"], [$code, $out]);

	// an ignore before code on its line keeps covering that line, so there is nothing to review
	file_put_contents("$root/src/t.php", "<?php\nfoo();\n/* phpcs:ignore Squiz.WhiteSpace.SuperfluousWhitespace */ \$a = 1;\n");
	[$code, $out] = runApp($root, ['migrate', 'suppressions', 'src/t.php']);
	Assert::same([0, "REWRITTEN  1 comment in 1 file\n"], [$code, $out]);

	// prose naming a directive is no directive, and every name of a list is rewritten
	$code = "<?php\n// prose phpcs:ignoreFile is an example\n/** @phpcsSuppress Squiz.WhiteSpace.SuperfluousWhitespace, Squiz.WhiteSpace.SuperfluousWhitespace */\nclass U\n{\n}\n";
	file_put_contents("$root/src/u.php", $code);
	runApp($root, ['migrate', 'suppressions', 'src/u.php']);
	Assert::same(
		"<?php\n// prose phpcs:ignoreFile is an example\n/** @phpcsSuppress file.trailingWhitespace, file.trailingWhitespace */\nclass U\n{\n}\n",
		file_get_contents("$root/src/u.php"),
	);

	// a file that does not parse is skipped, which the exit code says
	file_put_contents("$root/src/v.php", "<?php function {\n");
	[$code, $out] = runApp($root, ['migrate', 'suppressions', 'src/v.php']);
	Assert::same(2, $code);
	Assert::match(
		"Skipped    src/v.php, its comments left as they are: %a%\n\nFAILED  1 file skipped, no comments in no files rewritten\n",
		$out,
	);
});


test('migrate suppressions narrows a directory holding the configured paths to them', function () {
	$root = createConsoleProject();
	mkdir("$root/other");
	file_put_contents("$root/other/s.php", "<?php\n// phpcs:ignoreFile\n");
	[, $out] = runApp($root, ['migrate', 'suppressions', '.']);
	Assert::same("OK  no phpcs comments to rewrite\n", $out);
	Assert::same("<?php\n// phpcs:ignoreFile\n", file_get_contents("$root/other/s.php"));
	[, $out] = runApp("$root/other", ['migrate', 'suppressions', '.']);
	Assert::match('REWRITTEN  1 comment in 1 file%A?%', $out);
	Assert::same("<?php\n// dresscode:ignoreFile\n", file_get_contents("$root/other/s.php"));
});
