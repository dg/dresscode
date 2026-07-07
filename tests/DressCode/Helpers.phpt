<?php declare(strict_types=1);

use DressCode\Helpers;
use Tester\Assert;


require __DIR__ . '/../bootstrap.php';


test('a pattern with a slash is anchored to the root', function () {
	Assert::true(Helpers::matchGlob('tests/fixtures/*', 'tests/fixtures/a.php'));
	Assert::true(Helpers::matchGlob('tests/fixtures/*', 'tests/fixtures/dir/a.php'));
	Assert::true(Helpers::matchGlob('tests/fixtures', 'tests/fixtures/a.php'));
	Assert::false(Helpers::matchGlob('tests/fixtures', 'tests/fixtures.old/a.php'));
	Assert::false(Helpers::matchGlob('fixtures/*', 'tests/fixtures/a.php'));
	Assert::true(Helpers::matchGlob('./tests/', 'tests/a.php'));
});


test('a pattern without a slash matches a segment at any depth', function () {
	Assert::true(Helpers::matchGlob('vendor', 'vendor/a.php'));
	Assert::true(Helpers::matchGlob('vendor', 'src/vendor/a.php'));
	Assert::false(Helpers::matchGlob('vendor', 'src/vendors/a.php'));
	Assert::true(Helpers::matchGlob('fixtures*', 'tests/fixtures.old/a.php'));
	Assert::true(Helpers::matchGlob('*.phpt', 'tests/a.phpt'));
	Assert::false(Helpers::matchGlob('*.phpt', 'tests/a.php'));
});


test('wildcards', function () {
	Assert::false(Helpers::matchGlob('src/*.php', 'src/dir/a.php'));
	Assert::true(Helpers::matchGlob('src/**.php', 'src/dir/a.php'));
	Assert::true(Helpers::matchGlob('src/**/a.php', 'src/x/y/a.php'));
	Assert::true(Helpers::matchGlob('a?.php', 'ab.php'));
	Assert::false(Helpers::matchGlob('a?.php', 'a/.php'));
	Assert::true(Helpers::matchGlob('tests\fixtures', 'tests/fixtures/a.php'));
});


test('character classes', function () {
	Assert::true(Helpers::matchGlob('*.[jt]s', 'src/a.ts'));
	Assert::true(Helpers::matchGlob('*.[jt]s', 'src/a.js'));
	Assert::false(Helpers::matchGlob('*.[jt]s', 'src/a.cs'));
	Assert::true(Helpers::matchGlob('a[0-9].php', 'a4.php'));
	Assert::false(Helpers::matchGlob('a[0-9].php', 'ax.php'));
	Assert::true(Helpers::matchGlob('a[!0-9].php', 'ax.php'));
	Assert::false(Helpers::matchGlob('a[!0-9].php', 'a4.php'));
});


test('a newline does not end the path', function () {
	Assert::false(Helpers::matchGlob('*.php', "a.php\n"));
});


test('canonicalizePath', function () {
	Assert::same('a/b', Helpers::canonicalizePath('a\b'));
	Assert::same('a/b', Helpers::canonicalizePath('a/b/'));
	Assert::same('C:/a', Helpers::canonicalizePath('C:\a\\'));
	Assert::same('', Helpers::canonicalizePath(''));
	Assert::same('a/../b/.', Helpers::canonicalizePath('a/../b/.')); // unlike FileSystem::normalizePath()
});
