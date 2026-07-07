<?php declare(strict_types=1);

use DressCode\Engine\PathGlob;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a pattern with a slash is anchored to the root', function () {
	Assert::true(PathGlob::match('tests/fixtures/*', 'tests/fixtures/a.php'));
	Assert::true(PathGlob::match('tests/fixtures/*', 'tests/fixtures/dir/a.php'));
	Assert::true(PathGlob::match('tests/fixtures', 'tests/fixtures/a.php'));
	Assert::false(PathGlob::match('tests/fixtures', 'tests/fixtures.old/a.php'));
	Assert::false(PathGlob::match('fixtures/*', 'tests/fixtures/a.php'));
	Assert::true(PathGlob::match('./tests/', 'tests/a.php'));
});


test('a pattern without a slash matches a segment at any depth', function () {
	Assert::true(PathGlob::match('vendor', 'vendor/a.php'));
	Assert::true(PathGlob::match('vendor', 'src/vendor/a.php'));
	Assert::false(PathGlob::match('vendor', 'src/vendors/a.php'));
	Assert::true(PathGlob::match('fixtures*', 'tests/fixtures.old/a.php'));
	Assert::true(PathGlob::match('*.phpt', 'tests/a.phpt'));
	Assert::false(PathGlob::match('*.phpt', 'tests/a.php'));
});


test('wildcards', function () {
	Assert::false(PathGlob::match('src/*.php', 'src/dir/a.php'));
	Assert::true(PathGlob::match('src/**.php', 'src/dir/a.php'));
	Assert::true(PathGlob::match('src/**/a.php', 'src/x/y/a.php'));
	Assert::true(PathGlob::match('a?.php', 'ab.php'));
	Assert::false(PathGlob::match('a?.php', 'a/.php'));
	Assert::true(PathGlob::match('tests\fixtures', 'tests/fixtures/a.php'));
});
