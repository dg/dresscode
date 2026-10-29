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
		Upgrade    php 8.4 over 1 file, the names resolved for certain as measured, without the types of the code

		Must change, 3 topics with 3 findings
		  php                             1 finding in 1 file       needs ^8.4, 1 manual
		  php                             1 finding in 1 file       works on ^8.0, automatic
		  upgrading.php.implicitNullable  1 finding in 1 file       works on ^8.0, automatic

		Today      2 findings of `deprecations` are rewritten on the versions allowed now by `dresscode upgrade today`
		Data       php up to %d%.%d%
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


test('upgrade plan writes the steps, changing nothing', function () {
	$root = createTempDir('upgrade-plan');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"type": "project", "require": {"php": "^8.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\n");
	$code = "<?php\n\nfunction run(\$handle, int \$count = null): void\n{\n\tcurl_close(\$handle);\n\tstrftime('%Y');\n}\n";
	file_put_contents("$root/src/Code.php", $code);
	[$exit, $out, $err] = runApp($root, ['upgrade', 'plan', 'php', '8.4', '--no-types', '--offline', '--no-color']);
	Assert::same('', $err);
	Assert::same(0, $exit);
	Assert::match(<<<'XX'
		%A%Plan       php 8.4
		  1  fix       works on the versions allowed today  php, upgrading.php.implicitNullable
		  2  composer  raise php to ^8.4
		%A%Tests      not run, no `--test-command` given
		Next       `--save` keeps the plan in `dresscode-upgrade.neon` for the team to review
		XX, $out);
	Assert::same($code, file_get_contents("$root/src/Code.php"));
	Assert::false(is_file("$root/dresscode-upgrade.neon"));
	// a tool reads the plan as data with the root of the project, and no identity of a plan not saved
	$data = json_decode(
		runApp($root, ['upgrade', 'plan', 'php', '8.4', '--no-types', '--offline', '--format', 'json'])[1],
		associative: true,
		flags: JSON_THROW_ON_ERROR,
	);
	Assert::match('%a%upgrade-plan', $data['root']);
	Assert::false(isset($data['planIdentity']));
	// planned as a library, which `^8.0` already lets onto PHP 8.4, the plan is the bridge alone
	$library = runApp($root, ['upgrade', 'plan', 'php', '8.4', '--library', '--no-types', '--offline', '--format', 'json'])[1];
	Assert::same(['fix'], array_column(json_decode($library, associative: true, flags: JSON_THROW_ON_ERROR)['steps'], 'kind'));
});


test('a plan of no step is saved as no file', function () {
	$root = createTempDir('plan-empty');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.4"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\ncacheDir: cache\n");
	file_put_contents("$root/src/Code.php", "<?php\n");

	[$exit, $out] = runApp($root, ['upgrade', 'plan', 'php', '8.4', '--save', '--no-types', '--offline', '--no-color']);
	Assert::same(0, $exit);
	Assert::contains('Saved      nothing, as the upgrade has no step to make', $out);
	Assert::false(is_file("$root/dresscode-upgrade.neon"));
});


test('upgrade plan without a target refuses a project on PHP 7, which is offered none', function () {
	$root = createTempDir('upgrade-plan-php7');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": "^7.4"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\n");
	file_put_contents("$root/src/Code.php", "<?php\n\$x = 1;\n");
	[$exit, , $err] = runApp($root, ['upgrade', 'plan', '--no-types', '--offline', '--no-color']);
	Assert::same(3, $exit);
	Assert::contains('There is no version to plan an upgrade to', $err);
});


test('upgrade plan says whether it plans a library or an application, and why', function () {
	$root = createTempDir('upgrade-plan-mode');
	mkdir("$root/src");
	file_put_contents("$root/composer.json", '{"require": {"php": "^8.0"}}');
	file_put_contents("$root/dresscode.neon", "paths: [src]\n");
	file_put_contents("$root/src/Code.php", "<?php\n\$x = 1;\n");
	$plan = fn() => runApp($root, ['upgrade', 'plan', 'php', '8.4', '--no-types', '--offline', '--no-color'])[1];
	Assert::contains(
		"Mode       library, `composer.json` gives no type, which Composer reads as `library`, and there is no `composer.lock`\n",
		$plan(),
	);
	file_put_contents("$root/composer.lock", '{"packages": []}');
	Assert::contains(
		'Mode       application, `composer.json` gives no type, which Composer reads as `library`, yet the project has a `composer.lock` of its own, as an application has; `--library` plans it as a library',
		$plan(),
	);
	file_put_contents("$root/composer.json", '{"type": "project", "require": {"php": "^8.0"}}');
	Assert::contains("Mode       application, `composer.json` gives the type `project`\n", $plan());
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
		"No data    nette/utils: the upgrading data are in `dresscode/upgrade-nette`, which `dresscode upgrade today` offers to install, with `--yes` without asking\n",
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
		[
			['upgrade', 'plan', '--quick-test-command', 'phpunit'],
			'A command of the tests writes its JUnit report where `{junit}` stands, and `phpunit` has none.',
		],
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
