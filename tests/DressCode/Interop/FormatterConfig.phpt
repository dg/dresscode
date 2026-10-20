<?php declare(strict_types=1);

use DressCode\Interop\{Formatter, FormatterConfig};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the formatter of a configuration is told by its file', function () {
	$root = createTempDir('foreign-find');
	file_put_contents("$root/.php-cs-fixer.dist.php", "<?php\n");
	$config = FormatterConfig::fromFile($root, '.php-cs-fixer.dist.php');
	Assert::same([Formatter::PhpCsFixer, '.php-cs-fixer.dist.php'], [$config->formatter, $config->file]);
});
