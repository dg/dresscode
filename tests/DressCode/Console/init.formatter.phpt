<?php declare(strict_types=1);

/**
 * init reads what the configuration of another formatter says about the scope and the standard, as text and
 * never by running it.
 */

use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/init.helpers.php';


$tabbed = "<?php\n\nclass A\n{\n\tpublic function b(): string\n\t{\n\t\treturn 'x' . 'y' . 'z';\n\t}\n}\n";


test('what of the scope of the formatter could not be read is said', function () use ($tabbed) {
	$root = createProject('formatter-unread', [
		'src/A.php' => $tabbed,
		'.php-cs-fixer.dist.php' => "<?php\n\$dirs = glob(__DIR__ . '/*');\nreturn (new PhpCsFixer\\Config)->setFinder(PhpCsFixer\\Finder::create()->in(\$dirs));\n",
	]);
	[, $out] = runInit($root, ['--use', 'perCs']);
	Assert::contains('its scope may differ, as `in($dirs)` could not be read', $out);
});


test('of several formatters nothing tells apart the first is taken, the others said', function () use ($tabbed) {
	$root = createProject('formatter-several', [
		'src/A.php' => $tabbed,
		'phpcs.xml' => '<?xml version="1.0"?><ruleset name="x"/>',
		'pint.json' => '{}',
	]);
	[, $out] = runInit($root, ['--use', 'perCs']);
	Assert::match(
		"%A%Formatter  PHP_CodeSniffer, `phpcs.xml`\n%s%taken as the first, the project having also `pint.json` of Laravel Pint; a terminal asks which, without `--yes`\n%A%",
		$out,
	);

	// one named is no guess among them
	[, $out] = runInit($root, ['--use', 'perCs', '--dry-run', '--formatter', 'pint.json']);
	Assert::contains("Formatter  Laravel Pint, `pint.json`\n", $out);
	Assert::notContains('taken as the first', $out);
});


test('init proposes the standard of the preset Laravel Pint formats with, as the package or its configuration says', function () {
	foreach ([
		'package' => ['composer.lock' => '{"packages": [], "packages-dev": [{"name": "laravel/pint"}]}'],
		'configuration' => ['pint.json' => '{"preset": "laravel"}'],
	] as $name => $files) {
		$root = createProject(
			"pint-$name",
			['src/a.php' => "<?php\n", 'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src"}}}', ...$files],
		);
		[$code, $out] = runInit($root);
		Assert::same(0, $code);
		Assert::contains('Standard   laravel (the style of Laravel Pint), Laravel Pint formats the project in this style', $out);
		Assert::contains(
			'use: laravel   # Laravel Pint formats the project in this style',
			(string) file_get_contents("$root/dresscode.neon"),
		);
	}

	// another preset of Pint is its standard, and one no standard is leaves it to the code, which names no standard to win a tie
	foreach (['per' => 'perCs', 'psr12' => 'psr12', 'symfony' => 'symfony', 'empty' => 'perCs'] as $preset => $standard) {
		$root = createProject(
			"pint-$preset",
			['src/a.php' => "<?php\n", 'pint.json' => "{\"preset\": \"$preset\"}", 'composer.json' => '{}'],
		);
		[, $out] = runInit($root);
		Assert::contains("Standard   $standard (", $out);
	}
	Assert::contains('the nearest to your code', $out);

	// a standard given wins
	$root = createProject('pint-given', ['src/a.php' => "<?php\n", 'pint.json' => '{}']);
	runInit($root, ['--use', 'nette']);
	Assert::contains("use: nette\n", (string) file_get_contents("$root/dresscode.neon"));
});


test(
	'the configuration of another formatter is read as text and never run, and what it leaves out init leaves out too',
	function () use ($tabbed) {
		$root = createProject('foreign', [
			'composer.json' => '{"scripts": {"cs": "php-cs-fixer fix"}}',
			'.php-cs-fixer.dist.php' => "<?php\nexit(1);\nreturn (new PhpCsFixer\\Config)->setRules(['@PSR12' => true])\n"
				. "\t->setFinder(PhpCsFixer\\Finder::create()->in(__DIR__ . '/src')->exclude('Generated'));\n",
			'src/A.php' => $tabbed,
			'src/S.php' => "<?php\n// phpcs:ignoreFile\n",
			'src/Generated/B.php' => "<?php\n  \$a = 1; // phpcs:ignore Generic.Files.LineLength\n",
		]);
		[$code, $out] = runInit($root);
		Assert::same(0, $code);
		Assert::match(
			'%A%Excluded   src/Generated   left out by your formatter%A%Formatter  PHP CS Fixer, `.php-cs-fixer.dist.php`, run by `composer cs`%A%',
			$out,
		);
		Assert::contains(
			"Also       `dresscode check` can take the place of PHP CS Fixer in `composer cs`\n"
			. "           its rules were not read, as `.php-cs-fixer.dist.php` is PHP; `dresscode migrate config .php-cs-fixer.dist.php` translates them instead of measuring the code\n"
			. "           1 phpcs suppression comment: `dresscode migrate suppressions` rewrites them\n",
			$out,
		);
		Assert::contains("\t- src/Generated   # left out by your formatter\n", (string) file_get_contents("$root/dresscode.neon"));
	},
);


test('a rule a ruleset of PHP_CodeSniffer leaves out somewhere is unchecked there', function () {
	$camel = "<?php\n\nclass A\n{\n\tpublic function getName(): void\n\t{\n\t}\n}\n";
	$snake = "<?php\n\nclass B\n{\n\tpublic function get_name(): void\n\t{\n\t}\n}\n";
	$root = createProject('ruleset', [
		'composer.json' => '{}',
		'phpcs.xml' => '<?xml version="1.0"?><ruleset><rule ref="PSR12"/><rule ref="PSR1.Methods.CamelCapsMethodName.NotCamelCaps">'
			. '<exclude-pattern>src/legacy/</exclude-pattern></rule></ruleset>',
		'src/A.php' => $camel,
		'src/legacy/B.php' => $snake,
		'src/legacy/C.php' => str_replace('B', 'C', $snake),
	]);
	[$code, $out] = runInit($root, ['--use', 'psr12']);
	Assert::same(0, $code);
	Assert::contains('Formatter  PHP_CodeSniffer, `phpcs.xml`', $out);
	Assert::match(
		"%A%overrides:\n\t# where PSR1.Methods.CamelCapsMethodName.NotCamelCaps is left out by your formatter\n\t-\n\t\tpaths:\n\t\t\t- src/legacy\n\t\tnaming:\n\t\t\tmethod: keep\n",
		(string) file_get_contents("$root/dresscode.neon"),
	);
	Assert::notContains('baseline', (string) file_get_contents("$root/dresscode.neon"));
});
