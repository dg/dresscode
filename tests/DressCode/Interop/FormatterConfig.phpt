<?php declare(strict_types=1);

use DressCode\Interop\{Formatter, FormatterConfig};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the formatter of a configuration is told by its file', function () {
	$root = createTempDir('foreign-find');
	file_put_contents("$root/pint.json", '{"preset": "per"}');
	file_put_contents("$root/.php-cs-fixer.dist.php", "<?php\n");
	$config = FormatterConfig::fromFile($root, '.php-cs-fixer.dist.php');
	Assert::same([Formatter::PhpCsFixer, '.php-cs-fixer.dist.php'], [$config->formatter, $config->file]);
	Assert::same(Formatter::Pint, FormatterConfig::fromFile($root, 'pint.json')->formatter);
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
