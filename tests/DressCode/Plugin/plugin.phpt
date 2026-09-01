<?php declare(strict_types=1);

/**
 * End-to-end: a plugin with a rule, an analysis and a preset, wired only by the configuration file.
 */

use DressCode\Console\Application;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * @param  list<string>  $args
 * @return array{int, string, string}
 */
function runPlugin(array $args): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$code = (new Application($out, $err, cwd: __DIR__ . '/fixtures/project'))->run(['dresscode', ...$args]);
	rewind($out);
	rewind($err);
	return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}


test('check with the plugin preset', function () {
	[$code, $out, $err] = runPlugin(['check', '--diff']);
	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::match(<<<'XX'
		DRESS|CODE %a%
		Config     %a%dresscode.php
		Target     PHP %a%
		Checking   1 file in %a%project%a%src

		src%a%a.php
		  error   5:1  Imports are not sorted           ordered-imports
		  error   6:1  Imports are not sorted           ordered-imports
		  error  10:5  Call of print_r() is forbidden   acme/no-var-dump
		  error  11:5  Call of var_dump() is forbidden  acme/no-var-dump
		--- src/a.php
		+++ src/a.php
		@@ -2,8 +2,8 @@

		 namespace App;

		-use B;
		 use A;
		+use B;

		 function f()
		 {

		FOUND  4 violations, 2 of them fixable in 1 file

		XX, $out);
});


test('the plugin rule is known by name', function () {
	[$code, $out] = runPlugin(['rules']);
	Assert::same(0, $code);
	Assert::match('%A?%* acme/no-var-dump %s%Structure  Forbids debugging function calls%A%* dresscode/eof-newline%A%  dresscode/no-global-keyword%A%* dresscode/no-trailing-whitespace%A%', $out);
	[$code, $out] = runPlugin(['check', '--rule', 'acme/no-var-dump=off', '--rule', 'dresscode/ordered-imports=off', '--rule', 'dresscode/no-trailing-whitespace=off']);
	Assert::same(0, $code);
	Assert::match("%A%OK  no violations in 1 file\n", $out);
});
