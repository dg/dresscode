<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{PackageProfiles, ProjectPackages, RunnerFactory};
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * A project with the packages Composer would install: the entry of each in installed.json with what its composer.json
 * says under extra, and the files of the project and of the packages.
 * @param  array<string, array{string, array<string, mixed>}>  $packages  name → [normalized version, extra.dresscode]
 * @param  array<string, string>  $files  path → content
 * @param  array<string, mixed>  $root  what the composer.json of the project says besides its name
 */
function project(string $name, array $packages, array $files, array $root = []): string
{
	$dir = str_replace('\\', '/', __DIR__) . "/../../temp/package-profiles/$name";
	FileSystem::delete($dir);
	FileSystem::write("$dir/composer.json", json_encode(['name' => 'app/project'] + $root, JSON_THROW_ON_ERROR));
	$installed = [];
	foreach ($packages as $package => [$version, $extra]) {
		$installed[] = [
			'name' => $package,
			'version' => $version,
			'version_normalized' => $version,
			'install-path' => "../$package",
			'extra' => ['dresscode' => $extra],
		];
	}

	FileSystem::write("$dir/vendor/composer/installed.json", json_encode(['packages' => $installed, 'dev' => true, 'dev-package-names' => []], JSON_THROW_ON_ERROR));
	foreach ($files as $file => $content) {
		FileSystem::write("$dir/$file", $content);
	}

	return $dir;
}


test('the profile a package ships applies up to its installed version, the one of the root package whole', function () {
	$root = project(
		'versions',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		[
			'vendor/acme/lib/upgrading.neon' => <<<'XX'
				package: acme/lib

				since 3.5:
					replaced-classes:
						Acme\Lib\Newest: Acme\Lib\Future

				since 3.0:
					replaced-classes:
						Acme\Lib\Old: Acme\Lib\Renamed
						Acme\Lib\Older: Acme\Lib\Renamed
					replaced-functions:
						acme_gone: acme_kept

				since 3.2:
					replaced-classes:
						Acme\Lib\Old: Acme\Lib\RenamedAgain
				XX,
			'root.neon' => <<<'XX'
				package: app/project

				since 9.9:
					replaced-classes:
						App\Old: App\Renamed
				XX,
		],
		['extra' => ['dresscode' => ['upgrading' => 'root.neon']]],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same([], $packages->warnings);
	Assert::same([], $packages->extensions);
	Assert::same(['root.neon of app/project', 'upgrading.neon of acme/lib'], array_column($packages->profiles, 'source'));

	// every section of the root package, whose version says nothing
	Assert::same([], $packages->profiles[0]->unreached);
	Assert::same(['replaced-classes' => ['App\Old' => 'App\Renamed']], $packages->profiles[0]->profile->rules);

	// the sections up to 3.2 in the order of their versions, a later one having the last word on a key; 3.5 is not reached
	Assert::same(
		[
			'replaced-classes' => ['Acme\Lib\Old' => 'Acme\Lib\RenamedAgain', 'Acme\Lib\Older' => 'Acme\Lib\Renamed'],
			'replaced-functions' => ['acme_gone' => 'acme_kept'],
		],
		$packages->profiles[1]->profile->rules,
	);
	Assert::same(['3.5'], $packages->profiles[1]->unreached);
});


test('a later section has the last word on an entry, and a value NEON reads as an entity stays one for the schema of the rule', function () {
	$root = project(
		'keep',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		[
			'vendor/acme/lib/upgrading.neon' => <<<'XX'
				package: acme/lib

				since 3:
					replaced-members:
						Acme\Lib\Order::OLD: New
						Acme\Lib\Order::$paid: isPaid()

				since 3.2:
					replaced-members:
						Acme\Lib\Order::OLD: keep

				since 4:
					replaced-members:
						Acme\Lib\Order::$paid: keep

				since 3.10:
					replaced-members: []
				XX,
		],
	);

	[$profile] = PackageProfiles::discover(ProjectPackages::read($root))->profiles;
	Assert::equal(
		['replaced-members' => ['Acme\Lib\Order::OLD' => 'keep', 'Acme\Lib\Order::$paid' => new Nette\Neon\Entity('isPaid')]],
		$profile->profile->rules,
	);
	Assert::same(['3.10', '4'], $profile->unreached);
});


test('a profile for a package that is not installed is left out, and a package may carry the profile of another', function () {
	$root = project(
		'carrier',
		[
			'acme/lib' => ['3.2.0.0', []],
			'acme/rules' => ['9999999-dev', ['upgrading' => ['upgrading/other.neon', 'upgrading/lib.neon']]],
		],
		[
			'vendor/acme/rules/upgrading/other.neon' => "package: acme/other\n\nsince 1.0:\n\treplaced-classes:\n\t\tAcme\\Other\\Old: Acme\\Other\\Renamed\n",
			'vendor/acme/rules/upgrading/lib.neon' => "package: acme/lib\n\nsince 3.0:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n",
		],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['upgrading/lib.neon of acme/rules'], array_column($packages->profiles, 'source'));
	// measured against the version of acme/lib, not of the package carrying the file
	Assert::same(['replaced-classes' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->profiles[0]->profile->rules);
});


test('a package the project requires itself is measured by the lowest version its constraint allows, not the installed one', function () {
	$root = project(
		'required',
		['acme/lib' => ['3.4.0.0', ['upgrading' => 'upgrading.neon']]],
		['vendor/acme/lib/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n"],
		['require' => ['acme/lib' => '^3.1']],
	);

	// the code still has to run on 3.1, where the name of 3.3 does not exist yet
	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['replaced-classes' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->profiles[0]->profile->rules);
	Assert::same(['3.3'], $packages->profiles[0]->unreached);

	// unless the configuration says the code is written for 3.3 already
	$packages = PackageProfiles::discover(ProjectPackages::read($root)->withTargets(['acme/lib' => '3.3']));
	Assert::same(['replaced-classes' => ['Acme\Lib\Old' => 'Acme\Lib\Later']], $packages->profiles[0]->profile->rules);
	Assert::same([], $packages->profiles[0]->unreached);

	$factory = new RunnerFactory;
	$runner = $factory->createRunner(new Config(rules: ['replaced-classes' => true], packages: ['acme/lib' => '3.3', 'acme/ghost' => '1.0']), $root, cache: false);
	Assert::same("<?php\n\nnamespace App;\n\nnew \\Acme\\Lib\\Later;\n", $runner->processFile("$root/f.php", "<?php\n\nnamespace App;\n\nnew \\Acme\\Lib\\Old;\n")->output);
	Assert::same(["The configuration names package acme/ghost in 'packages', but it is not installed; skipped."], $factory->getWarnings());
});


test('a package names its extension, and one whose class is missing is a warning', function () {
	$root = project(
		'extensions',
		[
			'acme/rules' => ['9999999-dev', ['extension' => 'Acme\DressCode\Extension']],
			'acme/ghost' => ['1.0.0.0', ['extension' => 'Acme\Missing\Extension']],
		],
		[],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['Acme\DressCode\Extension'], $packages->extensions);
	Assert::same(
		['Package acme/ghost names the extension Acme\Missing\Extension, which does not exist or is not an extension; skipped.'],
		$packages->warnings,
	);
});


test('a profile that turns a rule on, names an unknown key or is missing is an error naming it', function () {
	$errors = [
		"package: acme/lib\n\nsince 1.0:\n\treplaced-classes: true\n"
			=> "Upgrading file upgrading.neon of acme/lib: The rule 'replaced-classes' in 'since 1.0' must be a map of options; a package turns no rule on.",
		"package: acme/lib\n\nrules:\n\treplaced-classes: []\n"
			=> "Upgrading file upgrading.neon of acme/lib: Unexpected key 'rules'; the file holds 'package' and sections 'since <version>'.",
		"since 1.0:\n\treplaced-classes: []\n"
			=> "Upgrading file upgrading.neon of acme/lib: The key 'package' must name the package the sections are versions of, as vendor/name.",
		"package: acme/lib\n\nsince 1.0: [a, b]\n"
			=> "Upgrading file upgrading.neon of acme/lib: The section 'since 1.0' must be a map of rules to their options.",
		"package: acme/lib\n\nsince 1.0:\n\treplaced-classes: [a: b\n" => 'Upgrading file upgrading.neon of acme/lib is not valid NEON: %a%',
	];
	foreach ($errors as $content => $message) {
		$root = project('errors', ['acme/lib' => ['1.0.0.0', ['upgrading' => 'upgrading.neon']]], ['vendor/acme/lib/upgrading.neon' => $content]);
		Assert::exception(fn() => PackageProfiles::discover(ProjectPackages::read($root)), ConfigurationException::class, $message);
	}

	$root = project('missing', ['acme/lib' => ['1.0.0.0', ['upgrading' => 'upgrading.neon']]], []);
	Assert::exception(fn() => PackageProfiles::discover(ProjectPackages::read($root)), ConfigurationException::class, 'Upgrading file upgrading.neon of acme/lib does not exist.');
});


test('what a package says is heard of a rule the project runs, and never turns one on', function () {
	$root = project(
		'run',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		['vendor/acme/lib/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n"],
	);
	$code = <<<'XX'
		<?php

		namespace App;

		use Acme\Lib\Old;

		function f(Old $a, \App\Aged $b): void
		{
		}
		XX;

	$factory = new RunnerFactory;
	$runner = $factory->createRunner(new Config(rules: ['replaced-classes' => ['App\Aged' => 'App\Fresh']]), $root, cache: false);
	$result = $runner->processFile("$root/f.php", $code);
	Assert::same(
		<<<'XX'
			<?php

			namespace App;

			use Acme\Lib\Renamed;

			function f(Renamed $a, \App\Fresh $b): void
			{
			}
			XX,
		$result->output,
	);
	$rule = $factory->getResolvedConfig()->rules[0];
	Assert::same('dresscode/replaced-classes', $rule->name);
	Assert::same(
		[
			'Acme\Lib\Old' => [['upgrading.neon of acme/lib', 'Acme\Lib\Renamed']],
			'App\Aged' => [['the configuration', 'App\Fresh']],
		],
		$rule->getOrigins(),
	);

	// nothing of the project mentions the rule, so the package does not turn it on
	$factory->createRunner(new Config, $root, cache: false);
	$rules = array_column($factory->getResolvedConfig()->rules, null, 'name');
	Assert::same('no preset or rule of the configuration mentions it', $rules['dresscode/replaced-classes']->inactive);
});
