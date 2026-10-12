<?php declare(strict_types=1);

use DressCode\Rules\Upgrading\AttributeForAnnotationRule;
use DressCode\Testing\{RuleTester, TestFailure};
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a fixture with the types after one that loaded phpdoc-parser from the vendor fails on the mix', function () {
	$dir = __DIR__ . '/../Rules/fixtures/attributeForAnnotation';
	RuleTester::runFixture(AttributeForAnnotationRule::class, "$dir/phpdoc-tags-untyped.code");
	Assert::exception(
		fn() => RuleTester::runFixture(AttributeForAnnotationRule::class, "$dir/basic.code"),
		TestFailure::class,
		'%a%phpdoc-parser%a%',
	);
});
