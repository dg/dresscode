<?php declare(strict_types=1);

use DressCode\Interop\{Formatter, FormatterConfig};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


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
