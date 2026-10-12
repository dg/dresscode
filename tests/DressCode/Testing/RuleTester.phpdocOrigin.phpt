<?php declare(strict_types=1);

use DressCode\Rules\Upgrading\AttributeForAnnotationRule;
use DressCode\Testing\RuleTester;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('the fixtures of a rule with the types take phpdoc-parser from the archive of PHPStan, those without them too', function () {
	// the fixture without the types goes first, as the fixtures run in the order of their names
	$source = __DIR__ . '/../Rules/fixtures/attributeForAnnotation';
	$dir = createTempDir('phpdoc-origin');
	FileSystem::copy("$source/stubs", "$dir/stubs");
	foreach (['code', 'expected', 'violations'] as $ext) {
		FileSystem::copy("$source/phpdoc-tags-untyped.$ext", "$dir/a-untyped.$ext");
		FileSystem::copy("$source/basic.$ext", "$dir/b-typed.$ext");
	}

	RuleTester::run(AttributeForAnnotationRule::class, $dir);
	$classes = array_filter(
		[...get_declared_classes(), ...get_declared_interfaces()],
		fn(string $class) => str_starts_with($class, 'PHPStan\PhpDocParser\\'),
	);
	Assert::contains(PHPStan\PhpDocParser\Parser\TokenIterator::class, $classes);
	Assert::same([], array_values(array_filter(
		$classes,
		fn(string $class) => !str_starts_with((string) new ReflectionClass($class)->getFileName(), 'phar://'),
	)));
});
