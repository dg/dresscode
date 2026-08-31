<?php declare(strict_types=1);

/**
 * End-to-end: a plugin with a rule, an analysis and a preset, brought in by a plugin.
 */

use Acme\DressCode\Plugin;
use DressCode\Config;
use DressCode\Console\Application;
use Tester\{Assert, Helpers};

require __DIR__ . '/../../bootstrap.php';


/**
 * @param  list<string>  $args
 * @return array{int, string, string}
 */
function runPlugin(array $args, ?string $cwd = null, ?Config $default = null): array
{
	$out = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$err = fopen('php://memory', 'w+') ?: throw new RuntimeException;
	$app = new Application($out, $err, cwd: $cwd ?? __DIR__ . '/fixtures/project', defaultConfig: $default);
	$code = $app->run(['dresscode', ...$args]);
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
		Checking   %a%project%a%src%a%a.php

		src%a%a.php
		  error   5:1  Expected `use A;` here, as the imports are sorted by name.  imports.order
		  error   6:1  Expected `use B;` here, as the imports are sorted by name.  imports.order
		  error  10:5  Call of print_r() is forbidden.                             acme.debugCalls
		  error  11:5  Call of var_dump() is forbidden.                            acme.debugCalls
		--- src/a.php
		+++ src/a.php
		@@ -2,8 +2,8 @@

		 namespace App;

		-use B;
		 use A;
		+use B;

		 function f()
		 {

		FOUND  4 violations, a fix leaves 2 in 1 file

		XX, $out);
});


test('the decisions of a plugin rule are in the catalogue, under its section', function () {
	[$code, $out] = runPlugin(['catalogue']);
	Assert::same(0, $code);
	Assert::match('%A%* file.trailingWhitespace %A%* acme.debugCalls %s%A call of a debugging function is not left in the code%A%', $out);
	[$code, $out] = runPlugin([
		'check',
		'--set', 'acme.debugCalls=keep',
		'--set', 'imports.order=keep',
		'--set', 'file.trailingWhitespace=keep',
	]);
	Assert::same(0, $code);
	Assert::match("%A%OK  1 file, up to the dress code\n", $out);
});


test('a default configuration stands in for a missing file', function () {
	$root = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/dresscode-plugin';
	@mkdir($root, recursive: true); // @ directory may already exist
	Helpers::purge($root);
	@mkdir("$root/src"); // @ directory may already exist
	file_put_contents("$root/src/a.php", "<?php\n\nvar_dump(1);\n");

	[$code, $out, $err] = runPlugin(['check'], $root, new Config(use: ['acme/default', Plugin::class], paths: ['src']));
	Assert::same('', $err);
	Assert::same(1, $code);
	Assert::match('%A%Config     none, using Acme\DressCode\Plugin, acme/default%A%Call of var_dump() is forbidden.  acme.debugCalls%A%', $out);
});
