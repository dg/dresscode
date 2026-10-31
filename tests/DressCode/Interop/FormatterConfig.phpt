<?php declare(strict_types=1);

use DressCode\Interop\{Formatter, FormatterConfig};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the paths a configuration checks are read relative to its own directory, the root among them taking all', function () {
	$root = createTempDir('foreign-paths');
	foreach (['conf', 'src', 'lib', 'test'] as $dir) {
		@mkdir("$root/$dir"); // @ directory may already exist
	}

	file_put_contents(
		"$root/conf/phpcs.xml",
		'<?xml version="1.0"?><ruleset name="x"><file>../src</file><file>../missing</file></ruleset>',
	);
	Assert::same(['src'], FormatterConfig::fromFile($root, 'conf/phpcs.xml')->paths);

	// the root takes all, and an absolute path is where it says
	file_put_contents("$root/phpcs.xml", '<?xml version="1.0"?><ruleset name="x"><file>src</file><file>.</file></ruleset>');
	Assert::same(['.'], FormatterConfig::fromFile($root, 'phpcs.xml')->paths);
	file_put_contents("$root/phpcs.xml", '<?xml version="1.0"?><ruleset name="x"><file>' . $root . '/src</file></ruleset>');
	Assert::same(['src'], FormatterConfig::fromFile($root, 'phpcs.xml')->paths);

	file_put_contents(
		"$root/.php-cs-fixer.dist.php",
		"<?php\nreturn (new PhpCsFixer\\Config)->setFinder(PhpCsFixer\\Finder::create()->in(__DIR__ . '/lib')->in(__DIR__ . '/test'));\n",
	);
	Assert::same(['lib', 'test'], FormatterConfig::fromFile($root, '.php-cs-fixer.dist.php')->paths);
});


test('the configuration in the root is found in the order of the formatters, its formatter told by the file', function () {
	Assert::null(FormatterConfig::find(createTempDir('foreign-none')));
	$root = createTempDir('foreign-find');
	file_put_contents("$root/pint.json", '{"preset": "per"}');
	file_put_contents("$root/.php-cs-fixer.dist.php", "<?php\n");
	$config = FormatterConfig::find($root);
	Assert::same([Formatter::PhpCsFixer, '.php-cs-fixer.dist.php', null], [$config?->formatter, $config?->file, $config?->runBy]);
	Assert::same(
		[Formatter::Pint, 'perCs'],
		[FormatterConfig::fromFile($root, 'pint.json')->formatter, FormatterConfig::fromFile($root, 'pint.json')->standard],
	);
	Assert::true(Formatter::PhpCsFixer->runsCode());
	Assert::false(Formatter::PhpCodeSniffer->runsCode());
});


test('a preset Laravel Pint does not have is refused, and what of its configuration import does not carry over is said', function () {
	$root = createTempDir('foreign-pint');
	file_put_contents("$root/pint.json", '{"preset": "typo"}');
	Assert::exception(
		fn() => FormatterConfig::fromFile($root, 'pint.json')->translate(),
		DressCode\ConfigurationException::class,
		'File `pint.json` names the preset `typo`, which Laravel Pint does not have; it has `laravel`, %a%.',
	);

	file_put_contents("$root/pint.json", '{"preset": "empty", "notPath": ["legacy.php"]}');
	Assert::contains(
		'`notPath` of `pint.json` is not carried over; the scope of the configuration may differ from the one of Laravel Pint.',
		FormatterConfig::fromFile($root, 'pint.json')->translate()->warnings,
	);
});


test('a set of PHP CS Fixer names the standard import translates it to, @PhpCsFixer the standard symfony', function () {
	$root = createTempDir('foreign-sets');
	$standard = function (string $set) use ($root): ?string {
		file_put_contents("$root/.php-cs-fixer.php", "<?php\nreturn (new PhpCsFixer\\Config)->setRules(['$set' => true]);\n");
		return FormatterConfig::fromFile($root, '.php-cs-fixer.php')->standard;
	};
	Assert::same(
		['perCs', 'psr12', 'symfony', 'symfony', null],
		array_map($standard, ['@PER-CS2.0', '@PSR12', '@Symfony', '@PhpCsFixer', '@Foo']),
	);
});
