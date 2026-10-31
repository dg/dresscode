<?php declare(strict_types=1);

use DressCode\Measuring\NamespaceDeclarations;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the files the autoload loads one by one declare for the whole project, and a file that cannot be read is not known', function () {
	$root = createTempDir('declarations');
	mkdir("$root/bootstrap");
	file_put_contents("$root/composer.json", '{"autoload": {"files": ["./bootstrap/helpers.php", "missing.php"]}}');
	file_put_contents("$root/bootstrap/helpers.php", "<?php\n\nnamespace App;\n\nfunction helper(): void\n{\n}\n");

	$declarations = NamespaceDeclarations::collect($root, ['src/gone.php']);
	Assert::same(['App\helper'], $declarations->functions);
	Assert::same(['src/gone.php'], $declarations->unparsed);
});
