<?php declare(strict_types=1);

use DressCode\Interop\{Evidence, Formatter, FormatterCandidate, FormatterConfig, FormatterSearch};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the paths a configuration checks are read relative to its own directory, the root among them taking all', function () {
	$root = createTempDir('foreign-paths');
	foreach (['conf', 'src', 'lib', 'test'] as $dir) {
		@mkdir("$root/$dir"); // @ directory may already exist
	}

	file_put_contents("$root/conf/phpcs.xml", '<?xml version="1.0"?><ruleset name="x"><file>../src</file></ruleset>');
	Assert::same(['src'], FormatterConfig::fromFile($root, 'conf/phpcs.xml')->paths);

	// a path that does not exist is refused, as the formatter refuses it, rather than leaving the scope to the autoload
	file_put_contents(
		"$root/conf/phpcs.xml",
		'<?xml version="1.0"?><ruleset name="x"><file>../src</file><file>../missing</file></ruleset>',
	);
	Assert::exception(
		fn() => FormatterConfig::fromFile($root, 'conf/phpcs.xml'),
		DressCode\ConfigurationException::class,
		'`%a%conf/phpcs.xml` names `../missing`, which does not exist.',
	);

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

	// what the finder leaves out by a directory, a path and a name is what the scope leaves out
	file_put_contents(
		"$root/.php-cs-fixer.dist.php",
		"<?php\nreturn (new PhpCsFixer\\Config)->setFinder(PhpCsFixer\\Finder::create()->in(__DIR__ . '/lib')"
		. "->exclude('Cache')->notPath('#/Fixtures/#')->notName('*.phpt')->notName('/[.]draft[.]php$/'));\n",
	);
	$config = FormatterConfig::fromFile($root, '.php-cs-fixer.dist.php');
	Assert::true($config->excludes('lib/Cache/a.php'));
	Assert::true($config->excludes('lib/Http/Fixtures/a.php'));
	Assert::true($config->excludes('lib/Http/a.phpt'));
	Assert::true($config->excludes('lib/Http/a.draft.php'));
	Assert::false($config->excludes('lib/Http/a.php'));
	Assert::same([], $config->unread);

	// a pattern of a name is matched against the name, a finder narrowed by names says it, and appended files alone are the scope
	file_put_contents(
		"$root/.php-cs-fixer.dist.php",
		"<?php\nreturn (new PhpCsFixer\\Config)->setFinder(PhpCsFixer\\Finder::create()->in(__DIR__ . '/lib')"
		. "->notName('/^Legacy/')->name('Wanted.php'));\n",
	);
	$config = FormatterConfig::fromFile($root, '.php-cs-fixer.dist.php');
	Assert::true($config->excludes('lib/Legacy.php'));
	Assert::false($config->excludes('lib/OldLegacy.php'));
	Assert::same(['name()'], $config->unread);

	file_put_contents("$root/test/extra.php", "<?php\n");
	file_put_contents(
		"$root/.php-cs-fixer.dist.php",
		"<?php\nreturn (new PhpCsFixer\\Config)->setFinder(PhpCsFixer\\Finder::create()->append([__DIR__ . '/test/extra.php']));\n",
	);
	Assert::same(['test/extra.php'], FormatterConfig::fromFile($root, '.php-cs-fixer.dist.php')->paths);
});


test('a ruleset gives its extensions and the paths it ignores, and says what else of it is not carried over', function () {
	$root = createTempDir('foreign-ruleset-args');
	file_put_contents("$root/phpcs.xml", <<<'XX'
		<?xml version="1.0"?>
		<ruleset name="x">
			<arg name="extensions" value="php,inc/php"/>
			<arg name="ignore" value="*/cache/*"/>
			<arg name="tab-width" value="8"/>
			<arg value="sp"/>
			<arg name="colors"/>
			<arg name="report" value="full"/>
			<arg value="n"/>
			<config name="installed_paths" value="vendor/acme/standard"/>
			<config name="testVersion" value="8.1-"/>
			<ini name="memory_limit" value="1G"/>
			<custom/>
		</ruleset>
		XX);
	$config = FormatterConfig::fromFile($root, 'phpcs.xml');
	Assert::same(['php', 'inc'], $config->extensions);
	Assert::true($config->excludes('src/cache/a.php'));
	Assert::same(
		['<arg name="tab-width" value="8"/>', '<arg value="n"/>', '<config name="testVersion" value="8.1-"/>', '<custom>'],
		$config->unread,
	);
	Assert::contains(
		'`<arg name="tab-width" value="8"/>` of `phpcs.xml` is not carried over; what the configuration checks may differ from what PHP_CodeSniffer checked.',
		noteMessages($config->translate()),
	);
});


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
		noteMessages(FormatterConfig::fromFile($root, 'pint.json')->translate()),
	);

	// a file that is no JSON is told as such
	file_put_contents("$root/pint.json", '{"preset": ');
	Assert::same(['pint.json, which is no JSON'], FormatterConfig::fromFile($root, 'pint.json')->unread);
});
