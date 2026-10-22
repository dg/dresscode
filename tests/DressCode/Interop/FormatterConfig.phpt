<?php declare(strict_types=1);

use DressCode\Interop\{Evidence, Formatter, FormatterCandidate, FormatterConfig, FormatterSearch};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('without evidence the configurations go in the order of the formatters, a local one before the one distributed', function () {
	Assert::same([], FormatterSearch::find(createTempDir('foreign-none')));
	$root = createTempDir('foreign-find');
	file_put_contents("$root/pint.json", '{"preset": "per"}');
	file_put_contents("$root/.php-cs-fixer.dist.php", "<?php\n");
	Assert::same(
		['`.php-cs-fixer.dist.php` of PHP CS Fixer', '`pint.json` of Laravel Pint'],
		array_map(fn(FormatterCandidate $candidate) => $candidate->format(), FormatterSearch::find($root)),
	);
	Assert::equal(
		new FormatterCandidate(Formatter::PhpCsFixer, '.php-cs-fixer.dist.php', null, Evidence::File),
		FormatterSearch::find($root)[0],
	);

	// the local configuration is the one PHP CS Fixer reads
	file_put_contents("$root/.php-cs-fixer.php", "<?php\n");
	Assert::same('.php-cs-fixer.php', FormatterSearch::find($root)[0]->file);
	Assert::same(Formatter::Pint, FormatterConfig::fromFile($root, 'pint.json')->formatter);
	Assert::true(Formatter::PhpCsFixer->runsCode());
	Assert::false(Formatter::PhpCodeSniffer->runsCode());
});


test('the formatter a script runs is chosen over a file nothing runs, a package it requires over a file alone', function () {
	$root = createTempDir('foreign-evidence');
	@mkdir("$root/conf"); // @ directory may already exist
	file_put_contents("$root/phpcs.xml", '<?xml version="1.0"?><ruleset name="x"/>');
	file_put_contents("$root/pint.json", '{}');
	file_put_contents("$root/conf/fixer.php", "<?php\n");
	$scripts = fn(array $scripts, array $require = []) => file_put_contents(
		"$root/composer.json",
		json_encode(['scripts' => $scripts, 'require-dev' => $require]),
	);
	$find = fn() => array_map(fn(FormatterCandidate $candidate) => $candidate->format(), FormatterSearch::find($root));

	$scripts(['fix' => 'vendor/bin/pint --test']);
	Assert::same(['`pint.json` of Laravel Pint, run by `composer fix`'], $find());

	// a script calling another, the words of the shell with their quotes, and the file the option names
	$scripts(['cs' => '@lint', 'lint' => ['@php -d memory_limit=-1 "vendor/bin/php-cs-fixer" fix --config "conf/fixer.php"']]);
	Assert::same(['`conf/fixer.php` of PHP CS Fixer, run by `composer cs`'], $find());

	// two formatters a script runs are both evidenced
	$scripts(['cs' => 'vendor/bin/phpcs --standard=phpcs.xml && vendor/bin/pint']);
	Assert::same(['`phpcs.xml` of PHP_CodeSniffer, run by `composer cs`', '`pint.json` of Laravel Pint, run by `composer cs`'], $find());

	// a formatter is run by the program of a command, never by a word a command only passes on
	$scripts([
		'message' => 'echo pint',
		'lint' => 'XDEBUG_MODE=off php -d memory_limit=-1 vendor/bin/phpcs',
		'fix' => '@composer exec pint',
	]);
	Assert::same(['`phpcs.xml` of PHP_CodeSniffer, run by `composer lint`', '`pint.json` of Laravel Pint, run by `composer fix`'], $find());

	$scripts([], ['laravel/pint' => '^1.0']);
	Assert::same([Evidence::Package], array_map(fn(FormatterCandidate $candidate) => $candidate->evidence, FormatterSearch::find($root)));
});


test('a preset Laravel Pint does not have is refused, and what of its configuration is not carried over is said', function () {
	$root = createTempDir('foreign-pint');
	file_put_contents("$root/pint.json", '{"preset": "typo"}');
	Assert::exception(
		fn() => FormatterConfig::fromFile($root, 'pint.json')->translate(),
		DressCode\ConfigurationException::class,
		'File `%a%pint.json` names the preset `typo`, which Laravel Pint does not have; it has `laravel`, %a%.',
	);

	file_put_contents("$root/pint.json", '{"preset": "empty", "notPath": ["legacy.php"]}');
	Assert::contains(
		'`notPath` of `pint.json` is not carried over; what the configuration checks may differ from what Laravel Pint checked.',
		FormatterConfig::fromFile($root, 'pint.json')->translate()->warnings,
	);
});
