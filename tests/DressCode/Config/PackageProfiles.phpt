<?php declare(strict_types=1);

use DressCode\Config;
use DressCode\Config\PackageProfiles;
use DressCode\Config\ProjectPackages;
use DressCode\Config\RunnerFactory;
use DressCode\ConfigurationException;
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
		['acme/lib' => ['3.2.0.0', ['deprecations' => 'deprecations.neon']]],
		[
			'vendor/acme/lib/deprecations.neon' => <<<'XX'
				package: acme/lib

				since 3.5:
					replaced-classes:
						Acme\Lib\Newest: Acme\Lib\Future

				since 3.0:
					replaced-classes:
						Acme\Lib\Old: Acme\Lib\Renamed
						Acme\Lib\Older: Acme\Lib\Renamed
					replaced-classes:
						Acme\Lib\Gone: Acme\Lib\Kept

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
		['extra' => ['dresscode' => ['deprecations' => 'root.neon']]],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same([], $packages->warnings);
	Assert::same([], $packages->extensions);
	Assert::same(['root.neon of app/project', 'deprecations.neon of acme/lib'], array_column($packages->profiles, 0));

	// every section of the root package, whose version says nothing
	Assert::same(['replaced-classes' => ['App\Old' => 'App\Renamed']], $packages->profiles[0][1]->rules);

	// the sections up to 3.2 in the order of their versions, a later one having the last word on a key; 3.5 is not reached
	Assert::same(
		[
			'replaced-classes' => ['Acme\Lib\Old' => 'Acme\Lib\RenamedAgain', 'Acme\Lib\Older' => 'Acme\Lib\Renamed'],
			'replaced-classes' => ['Acme\Lib\Gone' => 'Acme\Lib\Kept'],
		],
		$packages->profiles[1][1]->rules,
	);
});


test('a profile for a package that is not installed is left out, and a package may carry the profile of another', function () {
	$root = project(
		'carrier',
		[
			'acme/lib' => ['3.2.0.0', []],
			'acme/rules' => ['9999999-dev', ['deprecations' => ['deprecations/other.neon', 'deprecations/lib.neon']]],
		],
		[
			'vendor/acme/rules/deprecations/other.neon' => "package: acme/other\n\nsince 1.0:\n\treplaced-classes:\n\t\tAcme\\Other\\Old: Acme\\Other\\Renamed\n",
			'vendor/acme/rules/deprecations/lib.neon' => "package: acme/lib\n\nsince 3.0:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n",
		],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['deprecations/lib.neon of acme/rules'], array_column($packages->profiles, 0));
	// measured against the version of acme/lib, not of the package carrying the file
	Assert::same(['replaced-classes' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->profiles[0][1]->rules);
});


test('a package the project requires itself is measured by the lowest version its constraint allows, not the installed one', function () {
	$root = project(
		'required',
		['acme/lib' => ['3.4.0.0', ['deprecations' => 'deprecations.neon']]],
		['vendor/acme/lib/deprecations.neon' => "package: acme/lib\n\nsince 3.0:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n"],
		['require' => ['acme/lib' => '^3.1']],
	);

	// the code still has to run on 3.1, where the name of 3.3 does not exist yet
	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['replaced-classes' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->profiles[0][1]->rules);
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
			=> "Deprecations deprecations.neon of acme/lib: 'replaced-classes' in 'since 1.0' must be a map of options; a package turns no rule on.",
		"package: acme/lib\n\nrules:\n\treplaced-classes: []\n"
			=> "Deprecations deprecations.neon of acme/lib: unexpected key 'rules'; the file holds 'package' and sections 'since <version>'.",
		"since 1.0:\n\treplaced-classes: []\n"
			=> "Deprecations deprecations.neon of acme/lib: 'package' must name the package the sections are versions of, as vendor/name.",
		"package: acme/lib\n\nsince 1.0: [a, b]\n"
			=> "Deprecations deprecations.neon of acme/lib: 'since 1.0' must be a map of rules to their options.",
		"package: acme/lib\n\nsince 1.0:\n\treplaced-classes: [a: b\n" => 'Deprecations deprecations.neon of acme/lib: %a%',
	];
	foreach ($errors as $content => $message) {
		$root = project('errors', ['acme/lib' => ['1.0.0.0', ['deprecations' => 'deprecations.neon']]], ['vendor/acme/lib/deprecations.neon' => $content]);
		Assert::exception(fn() => PackageProfiles::discover(ProjectPackages::read($root)), ConfigurationException::class, $message);
	}

	$root = project('missing', ['acme/lib' => ['1.0.0.0', ['deprecations' => 'deprecations.neon']]], []);
	Assert::exception(fn() => PackageProfiles::discover(ProjectPackages::read($root)), ConfigurationException::class, 'Deprecations deprecations.neon of acme/lib do not exist.');
});


test('what a package says is heard of a rule the project runs, and never turns one on', function () {
	$root = project(
		'run',
		['acme/lib' => ['3.2.0.0', ['deprecations' => 'deprecations.neon']]],
		['vendor/acme/lib/deprecations.neon' => "package: acme/lib\n\nsince 3.0:\n\treplaced-classes:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n"],
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
			'Acme\Lib\Old' => [['deprecations.neon of acme/lib', 'Acme\Lib\Renamed']],
			'App\Aged' => [['the configuration', 'App\Fresh']],
		],
		$rule->getOrigins(),
	);

	// nothing of the project mentions the rule, so the package does not turn it on
	$factory->createRunner(new Config, $root, cache: false);
	$rules = array_column($factory->getResolvedConfig()->rules, null, 'name');
	Assert::same('no preset or rule of the configuration mentions it', $rules['dresscode/replaced-classes']->inactive);
});
