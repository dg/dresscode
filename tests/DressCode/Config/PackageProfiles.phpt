<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException, RuleGroup};
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
	$dir = createTempDir($name);
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
				group: deprecations

				since 3.5:
					replacedClasses:
						Acme\Lib\Newest: Acme\Lib\Future

				since 3.0:
					replacedClasses:
						Acme\Lib\Old: Acme\Lib\Renamed
						Acme\Lib\Older: Acme\Lib\Renamed

				since 3.2:
					replacedClasses:
						Acme\Lib\Old: Acme\Lib\RenamedAgain
				XX,
			'root.neon' => <<<'XX'
				package: app/project
				group: deprecations

				since 9.9:
					replacedClasses:
						App\Old: App\Renamed
				XX,
		],
		['extra' => ['dresscode' => ['upgrading' => 'root.neon']]],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same([], $packages->warnings);
	Assert::same([], $packages->plugins);
	Assert::same(['root.neon of app/project', 'upgrading.neon of acme/lib'], array_column($packages->profiles, 'source'));

	// every section of the root package, whose version says nothing
	Assert::same(['replacedClasses' => ['App\Old' => 'App\Renamed']], $packages->profiles[0]->profile->rules);

	// the sections up to 3.2 in the order of their versions, a later one having the last word on a key; 3.5 is not reached
	Assert::same(
		[
			'replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\RenamedAgain', 'Acme\Lib\Older' => 'Acme\Lib\Renamed'],
		],
		$packages->profiles[1]->profile->rules,
	);
});


test('a profile for a package that is not installed is left out, and a package may carry the profile of another', function () {
	$root = project(
		'carrier',
		[
			'acme/lib' => ['3.2.0.0', []],
			'acme/rules' => ['9999999-dev', ['upgrading' => ['upgrading/other.neon', 'upgrading/lib.neon']]],
		],
		[
			'vendor/acme/rules/upgrading/other.neon' => "package: acme/other\ngroup: deprecations\n\nsince 1.0:\n\treplacedClasses:\n\t\tAcme\\Other\\Old: Acme\\Other\\Renamed\n",
			'vendor/acme/rules/upgrading/lib.neon' => "package: acme/lib\ngroup: deprecations\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n",
		],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['upgrading/lib.neon of acme/rules'], array_column($packages->profiles, 'source'));
	// measured against the version of acme/lib, not of the package carrying the file
	Assert::same(['replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->profiles[0]->profile->rules);
});


test('a package the project requires itself is measured by the lowest version its constraint allows, not the installed one', function () {
	$root = project(
		'required',
		['acme/lib' => ['3.4.0.0', ['upgrading' => 'upgrading.neon']]],
		['vendor/acme/lib/upgrading.neon' => "package: acme/lib\ngroup: deprecations\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n"],
		['require' => ['acme/lib' => '^3.1']],
	);

	// the code still has to run on 3.1, where the name of 3.3 does not exist yet
	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->profiles[0]->profile->rules);
});


test('a package names its plugin, and one whose class is missing is a warning', function () {
	$root = project(
		'plugins',
		[
			'acme/rules' => ['9999999-dev', ['plugin' => 'Acme\DressCode\Plugin']], // dresscode:ignore classNameReferenceForString
			'acme/ghost' => ['1.0.0.0', ['plugin' => 'Acme\Missing\Plugin']],
		],
		[],
	);

	$packages = PackageProfiles::discover(ProjectPackages::read($root));
	Assert::same(['Acme\DressCode\Plugin'], $packages->plugins); // dresscode:ignore classNameReferenceForString
	Assert::same(
		['Package `acme/ghost` names the plugin `Acme\Missing\Plugin`, which does not exist or is not a plugin; skipped.'],
		$packages->warnings,
	);
});


test('a key under extra.dresscode that DressCode does not read is a warning of the run', function () {
	$root = project('unknown-key', ['acme/lib' => ['1.0.0.0', ['upgrades' => []]]], []);
	$factory = new RunnerFactory;
	$factory->createRunner(new Config, $root, cache: false);
	Assert::same(
		['Package `acme/lib`: `extra.dresscode` in its `composer.json` holds the key `upgrades`, which this DressCode does not know; skipped.'],
		$factory->getWarnings(),
	);
});


test('a profile that turns a rule on, names an unknown key or is missing is an error naming it', function () {
	$errors = [
		"package: acme/lib\ngroup: deprecations\n\nsince 1.0:\n\treplacedClasses: true\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The rule `replacedClasses` in `since 1.0` must be a map of options; a package turns no rule on.',
		"package: acme/lib\ngroup: deprecations\n\nrules:\n\treplacedClasses: []\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: Unexpected key `rules`; the file holds `package`, `group`, `namespaces` and sections `since <version>`.',
		"since 1.0:\n\treplacedClasses: []\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The key `package` must name the package the sections are versions of, as `vendor/name`.',
		"package: acme/lib\ngroup: deprecations\n\nsince 1.0: [a, b]\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The section `since 1.0` must be a map of rules to their options.',
		"package: acme/lib\ngroup: deprecations\n\nsince 1.0:\n\treplacedClasses: [a: b\n" => 'Upgrading file `upgrading.neon` of `acme/lib` is not valid NEON: %a%',
		"package: acme/lib\ngroup: style\n\nsince 1.0:\n\treplacedClasses: []\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The key `group` must name the group the data are of, one of `cleanup`, %a%.',
		"package: acme/lib\n\nsince 1.0:\n\treplacedClasses: []\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The key `group` must name the group the data are of, one of `cleanup`, %a%.',
	];
	foreach ($errors as $content => $message) {
		$root = project('errors', ['acme/lib' => ['1.0.0.0', ['upgrading' => 'upgrading.neon']]], ['vendor/acme/lib/upgrading.neon' => $content]);
		Assert::exception(fn() => PackageProfiles::discover(ProjectPackages::read($root)), ConfigurationException::class, $message);
	}

	$root = project('missing', ['acme/lib' => ['1.0.0.0', ['upgrading' => 'upgrading.neon']]], []);
	Assert::exception(fn() => PackageProfiles::discover(ProjectPackages::read($root)), ConfigurationException::class, 'Upgrading file `upgrading.neon` of `acme/lib` does not exist.');
});


test('the group of a file is the intent of its data', function () {
	$root = project(
		'group',
		['acme/lib' => ['1.0.0.0', ['upgrading' => ['retired.neon', 'modern.neon']]]],
		[
			'vendor/acme/lib/retired.neon' => "package: acme/lib\ngroup: deprecations\n\nsince 1.0:\n\treplacedClasses: []\n",
			'vendor/acme/lib/modern.neon' => "package: acme/lib\ngroup: modernization\n\nsince 1.0:\n\treplacedClasses: []\n",
		],
	);
	$groups = array_map(fn($profile) => $profile->group, PackageProfiles::discover(ProjectPackages::read($root))->profiles);
	Assert::same([RuleGroup::Deprecations, RuleGroup::Modernization], $groups);
});


test('what a package says is heard of a rule the project runs, and never turns one on', function () {
	$root = project(
		'run',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		['vendor/acme/lib/upgrading.neon' => "package: acme/lib\ngroup: deprecations\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n"],
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
	$runner = $factory->createRunner(new Config(rules: ['replacedClasses' => ['App\Aged' => 'App\Fresh']]), $root, cache: false);
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
	Assert::same('dresscode/replacedClasses', $rule->name);
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
	Assert::same('no preset or rule of the configuration mentions it', $rules['dresscode/replacedClasses']->inactive);
});


test('what a package declares in its namespaces lies under the lists of the project', function () {
	$root = project(
		'namespaces',
		[
			'acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']],
		],
		[
			'vendor/acme/lib/upgrading.neon' => <<<'XX'
				package: acme/lib
				group: deprecations
				namespaces:
					functions: ['Acme\Lib\{helper}']

				XX,
		],
	);
	$factory = new RunnerFactory;
	$factory->createRunner(new Config(namespaces: ['functions' => ['App\format']]), $root, cache: false);
	$functions = $factory->getResolvedConfig()->namespacedFunctions;
	Assert::same('the configuration', $functions['App\format']);
	Assert::same('upgrading.neon of acme/lib', $functions['Acme\Lib\helper']);
});
