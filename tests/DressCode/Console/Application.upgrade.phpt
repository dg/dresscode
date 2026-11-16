<?php declare(strict_types=1);

use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/Application.helpers.php';


$root = createConsoleProject();


test('upgrade estimate measures what an upgrade changes, topic by topic, and changes nothing', function () {
	// outside the repository, whose configuration it would find
	$root = sys_get_temp_dir() . '/dresscode-estimate-' . getmypid();
	FileSystem::delete($root);
	FileSystem::createDir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0"}, "autoload": {"psr-4": {"App\\\\": "src/"}}}');
	$code = "<?php\n\nnamespace App;\n\nfunction run(\$handle, int \$count = null): void\n{\n\tcurl_close(\$handle);\n\tstrftime('%Y');\n}\n";
	file_put_contents("$root/src/Code.php", $code);

	[$exit, $out, $err] = runApp($root, ['upgrade', 'estimate', 'php', '8.4', '--no-types', '--offline', '--no-color']);
	Assert::same('', $err);
	Assert::same(0, $exit);
	Assert::match(<<<'XX'
		DRESS|CODE %a%
		Config     none, the scope `init` would write: src
		Upgrade    php 8.4 over 1 file, the names resolved for certain as measured, without the types of the code, an estimate: nothing was changed

		Must change, 3 findings
		  php                             1 finding in 1 file       1 left to you, once ^8.4 is installed
		  php                             1 finding in 1 file       fixed by DressCode, already on ^8.0
		  upgrading.php.implicitNullable  1 finding in 1 file       fixed by DressCode, already on ^8.0

		Right now  `dresscode upgrade today` can rewrite 2 of these, on the versions you have
		Rules for  `php` up to %d%.%d%
		XX, $out);
	Assert::same($code, file_get_contents("$root/src/Code.php"));

	[$exit, $out] = runApp($root, ['upgrade', 'estimate', 'php', '8.4', '--no-types', '--offline', '--format', 'json']);
	Assert::same(0, $exit);
	// workers measure what the process alone does
	Assert::same(
		$out,
		runApp($root, ['upgrade', 'estimate', 'php', '8.4', '--no-types', '--offline', '--format', 'json', '--jobs', '2'])[1],
	);
	$estimate = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same([['package' => 'php', 'version' => '8.4']], $estimate['targets']);
	Assert::same(
		['php jump ^8.4', 'php bridge ^8.0', 'upgrading.php.implicitNullable bridge ^8.0'],
		array_map(fn(array $topic) => "$topic[name] $topic[phase] $topic[constraint]", $estimate['topics']),
	);

	// the paths named narrow the configured ones
	file_put_contents("$root/src/Other.php", "<?php\n");
	[, $out] = runApp($root, ['upgrade', 'estimate', '--no-types', '--offline', '--format', 'json', '--paths', 'src/Other.php']);
	$estimate = json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same([1, []], [$estimate['files'], $estimate['topics']]);
	FileSystem::delete($root);
});


test('upgrade estimate --no-types measures without the types the configuration asks for', function () {
	$root = createTempDir('estimate-no-types');
	mkdir("$root/src");
	file_put_contents("$root/src/Code.php", "<?php\n");
	file_put_contents("$root/composer.json", '{"type": "project", "require": {"php": "^8.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\ncacheDir: cache\ntypeAnalysis: phpstan\n");

	[$exit, $out, $err] = runApp($root, ['upgrade', 'estimate', 'php', '8.4', '--no-types', '--offline', '--format', 'json']);
	Assert::same([0, ''], [$exit, $err]);
	Assert::false(json_decode($out, associative: true, flags: JSON_THROW_ON_ERROR)['types']);
});


test('upgrade names the package of upgrading data a library of the project has, which DressCode lacks', function () {
	$root = createTempDir('estimate-missing-data');
	mkdir("$root/src");
	file_put_contents("$root/src/Code.php", "<?php\n");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.2", "nette/utils": "^4.0", "acme/mail": "^1.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\ncacheDir: cache\n");
	FileSystem::write("$root/cache/data-packages.json", '{"dresscode/upgrade-nette": ["nette/", "latte/latte"]}');

	[$exit, $out] = runApp($root, ['upgrade', 'estimate', 'php', '8.4', '--no-types', '--offline', '--no-color']);
	Assert::same(2, $exit); // an estimate without the data of a library is not complete
	Assert::contains(
		"No data    `nette/utils`: the upgrading data are in `dresscode/upgrade-nette`, which `dresscode upgrade today` offers to install, with `--yes` without asking\n",
		$out,
	);
});


test('upgrade refuses an option its command does not take, and a target it cannot measure', function () use ($root) {
	foreach ([
		[['upgrade'], 'Missing command.'],
		[['upgrade', 'estimate', '--modernize'], 'Option `--modernize` belongs to command `upgrade today`.'],
		[['upgrade', 'today', 'php'], 'Unexpected argument `php`.'],
		[['upgrade', 'estimate', 'acme/mail'], 'Name the version of `acme/mail` to upgrade to, as `acme/mail 4.0`.'],
		[['upgrade', 'estimate', 'php', 'next'], 'The version to upgrade to is written as `8.4` or `4.0`, `next` given.'],
		[['upgrade', 'estimate', '--format', 'lines'], 'Option `--format`: expects `console` or `json`, `lines` given.'],
	] as [$args, $message]) {
		[$exit, , $err] = runApp($root, $args);
		Assert::same(3, $exit);
		Assert::match("%A%$message%A%", $err);
	}

	// outside the repository, whose composer.json it would find
	$empty = sys_get_temp_dir() . '/dresscode-estimate-empty-' . getmypid();
	FileSystem::createDir($empty);
	[$exit, , $err] = runApp($empty, ['upgrade', 'estimate']);
	FileSystem::delete($empty);
	Assert::same(3, $exit);
	Assert::contains('There is nothing to upgrade', $err);
});
