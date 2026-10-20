<?php declare(strict_types=1);

use DressCode\Interop\{Formatter, FormatterConfig};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the configuration in the root is found, its formatter told by the file', function () {
	Assert::null(FormatterConfig::find(createTempDir('foreign-none')));
	$root = createTempDir('foreign-find');
	file_put_contents("$root/.php-cs-fixer.dist.php", "<?php\n");
	$config = FormatterConfig::find($root);
	Assert::same([Formatter::PhpCsFixer, '.php-cs-fixer.dist.php', null], [$config?->formatter, $config?->file, $config?->runBy]);
	Assert::true(Formatter::PhpCsFixer->runsCode());
	Assert::false(Formatter::PhpCodeSniffer->runsCode());
});
