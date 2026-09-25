<?php declare(strict_types=1);

use DressCode\{Config, ConfigurationException};
use DressCode\Config\{PackageDiscovery, ProjectPackages, RunnerFactory};
use DressCode\Console\ConfigPrinter;
use Nette\CommandLine\{ColorDepth, Console};
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


test('the upgrading data a package ships applies up to its installed version, the one of the root package whole', function () {
	$root = project(
		'versions',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		[
			'vendor/acme/lib/upgrading.neon' => <<<'XX'
				package: acme/lib

				since 3.5:
					replacedClasses:
						Acme\Lib\Newest: Acme\Lib\Future

				since 3.0:
					replacedClasses:
						Acme\Lib\Old: Acme\Lib\Renamed
						Acme\Lib\Older: Acme\Lib\Renamed
					replacedMembers:
						Acme\Lib\Renamed::old: renamed

				since 3.2:
					replacedClasses:
						Acme\Lib\Old: Acme\Lib\RenamedAgain
				XX,
			'root.neon' => <<<'XX'
				package: app/project

				since 9.9:
					replacedClasses:
						App\Old: App\Renamed
				XX,
		],
		['extra' => ['dresscode' => ['upgrading' => 'root.neon']]],
	);

	$packages = PackageDiscovery::discover(ProjectPackages::read($root));
	Assert::same([], $packages->warnings);
	Assert::same([], $packages->plugins);
	Assert::same(['root.neon of app/project', 'upgrading.neon of acme/lib'], array_map(fn($data) => $data->layer->describe(), $packages->upgradingData));

	// every section of the root package, whose version says nothing
	Assert::same([], $packages->upgradingData[0]->unreached);
	Assert::same(['replacedClasses' => ['App\Old' => 'App\Renamed']], $packages->upgradingData[0]->maps);

	// the sections up to 3.2 in the order of their versions, a later one having the last word on a key; 3.5 is not reached
	Assert::same(
		[
			'replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\RenamedAgain', 'Acme\Lib\Older' => 'Acme\Lib\Renamed'],
			'replacedMembers' => ['Acme\Lib\Renamed::old' => 'renamed'],
		],
		$packages->upgradingData[1]->maps,
	);
	Assert::same(['3.5'], $packages->upgradingData[1]->unreached);
});


test('a later section has the last word on an entry, and a value NEON reads as an entity stays one for the grammar of the map', function () {
	$root = project(
		'keep',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		[
			'vendor/acme/lib/upgrading.neon' => <<<'XX'
				package: acme/lib

				since 3:
					replacedMembers:
						Acme\Lib\Order::OLD: New
						Acme\Lib\Order::$paid: isPaid()

				since 3.2:
					replacedMembers:
						Acme\Lib\Order::OLD: keep

				since 4:
					replacedMembers:
						Acme\Lib\Order::$paid: keep

				since 3.10:
					replacedMembers: []
				XX,
		],
	);

	[$data] = PackageDiscovery::discover(ProjectPackages::read($root))->upgradingData;
	Assert::equal(
		['replacedMembers' => ['Acme\Lib\Order::OLD' => 'keep', 'Acme\Lib\Order::$paid' => new Nette\Neon\Entity('isPaid')]],
		$data->maps,
	);
	Assert::same(['3.10', '4'], $data->unreached);
});


test('upgrading data for a package that is not installed is left out, and a package may carry the data of another', function () {
	$root = project(
		'carrier',
		[
			'acme/lib' => ['3.2.0.0', []],
			'acme/rules' => ['9999999-dev', ['upgrading' => ['upgrading/other.neon', 'upgrading/lib.neon']]],
		],
		[
			'vendor/acme/rules/upgrading/other.neon' => "package: acme/other\n\nsince 1.0:\n\treplacedClasses:\n\t\tAcme\\Other\\Old: Acme\\Other\\Renamed\n",
			'vendor/acme/rules/upgrading/lib.neon' => "package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n",
		],
	);

	$packages = PackageDiscovery::discover(ProjectPackages::read($root));
	Assert::same(['upgrading/lib.neon of acme/rules'], array_map(fn($data) => $data->layer->describe(), $packages->upgradingData));
	// measured against the version of acme/lib, not of the package carrying the file
	Assert::same(['replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->upgradingData[0]->maps);
});


test('a package the project requires itself is measured by the lowest version its constraint allows, not the installed one', function () {
	$root = project(
		'required',
		['acme/lib' => ['3.4.0.0', ['upgrading' => 'upgrading.neon']]],
		['vendor/acme/lib/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n"],
		['require' => ['acme/lib' => '^3.1']],
	);

	// the code still has to run on 3.1, where the name of 3.3 does not exist yet
	$packages = PackageDiscovery::discover(ProjectPackages::read($root));
	Assert::same(['replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed']], $packages->upgradingData[0]->maps);
	Assert::same(['3.3'], $packages->upgradingData[0]->unreached);

	// unless the configuration says the code is written for 3.3 already
	$packages = PackageDiscovery::discover(ProjectPackages::read($root)->withTargets(['acme/lib' => '3.3']));
	Assert::same(['replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\Later']], $packages->upgradingData[0]->maps);
	Assert::same([], $packages->upgradingData[0]->unreached);

	$factory = new RunnerFactory;
	$resolution = $factory->resolve(new Config(targets: ['acme/lib' => '3.3', 'acme/ghost' => '1.0'], decisions: ['upgrading' => ['libraries' => ['packages' => 'adopted']]]), $root);
	$runner = $factory->createRunner($resolution, cache: false);
	Assert::same("<?php\n\nnamespace App;\n\nnew \\Acme\\Lib\\Later;\n", $runner->processCode("$root/f.php", "<?php\n\nnamespace App;\n\nnew \\Acme\\Lib\\Old;\n")->output);
	Assert::same(['The configuration names package `acme/ghost` in `targets`, but it is not installed; skipped.' => null], $resolution->warnings);
});


test('a package names its plugin, and one whose class is missing or is not a plugin is a warning', function () {
	$root = project(
		'plugins',
		[
			'acme/rules' => ['9999999-dev', ['plugin' => 'Acme\DressCode\Plugin']], // dresscode:ignore literals.classNameInString
			'acme/ghost' => ['1.0.0.0', ['plugin' => 'Acme\Missing\Plugin']],
			'acme/odd' => ['1.0.0.0', ['plugin' => 'stdClass']],
		],
		[],
	);

	$packages = PackageDiscovery::discover(ProjectPackages::read($root));
	Assert::same(['acme/rules' => 'Acme\DressCode\Plugin'], $packages->plugins); // dresscode:ignore literals.classNameInString
	Assert::same(
		[
			'Package `acme/ghost` names the plugin `Acme\Missing\Plugin`, which the autoloader does not find; skipped.',
			'Package `acme/odd` names the plugin `stdClass`, which is not a plugin; skipped.',
		],
		$packages->warnings,
	);
});


final class PackagePlugin implements DressCode\Plugin
{
	public static string $preset = '';


	public function getManifest(): DressCode\PluginManifest
	{
		return new DressCode\PluginManifest(presets: ['acme/style' => self::$preset]);
	}
}


test('the plugin of a package loads where use names the package or the plugin, the root package needing no name', function () {
	$root = project('plugin-admitted', ['acme/rules' => ['1.0.0.0', ['plugin' => PackagePlugin::class]]], ['style.neon' => "decisions:\n\tfile: {bom: forbidden}\n"]);
	PackagePlugin::$preset = "$root/style.neon";
	$factory = new RunnerFactory;

	$resolution = $factory->resolve(new Config, $root);
	Assert::same(['Package `acme/rules` brings the plugin `PackagePlugin`, which loads only where `use` names the package or the plugin; skipped.' => null], $resolution->warnings);
	Assert::exception(fn() => (new RunnerFactory)->resolve(new Config(use: ['acme/style']), $root), ConfigurationException::class, 'Unknown preset `acme/style`.');

	foreach (['acme/rules', PackagePlugin::class] as $name) {
		$resolution = (new RunnerFactory)->resolve(new Config(use: [$name, 'acme/style']), $root);
		Assert::same([], $resolution->warnings, $name);
	}

	$own = project('plugin-own', [], ['style.neon' => "decisions:\n\tfile: {bom: forbidden}\n"], ['extra' => ['dresscode' => ['plugin' => PackagePlugin::class]]]);
	PackagePlugin::$preset = "$own/style.neon";
	Assert::same([], (new RunnerFactory)->resolve(new Config(use: ['acme/style']), $own)->warnings);
});


test('a key under extra.dresscode that DressCode does not read is a warning of the run', function () {
	$root = project('unknown-key', ['acme/lib' => ['1.0.0.0', ['upgrades' => []]]], []);
	$factory = new RunnerFactory;
	$resolution = $factory->resolve(new Config, $root);
	Assert::same(
		['Package `acme/lib`: `extra.dresscode` in its `composer.json` holds the key `upgrades`, which this DressCode does not know; skipped.' => null],
		$resolution->warnings,
	);
});


test('an upgrading file that turns a map on instead of giving its entries, names an unknown key or is missing is an error naming it', function () {
	$errors = [
		"package: acme/lib\n\nsince 1.0:\n\treplacedClasses: true\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The map `replacedClasses` in `since 1.0` must be a map of entries; a package turns no rule on.',
		"package: acme/lib\n\nrules:\n\treplacedClasses: []\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: Unexpected key `rules`; the file holds `package`, `namespaces` and sections `since <version>`.',
		"since 1.0:\n\treplacedClasses: []\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The key `package` must name the package the sections are versions of, as `vendor/name`.',
		"package: acme/lib\n\nsince 1.0: [a, b]\n"
			=> 'Upgrading file `upgrading.neon` of `acme/lib`: The section `since 1.0` must be a map of maps to their entries.',
		"package: acme/lib\n\nsince 1.0:\n\treplacedClasses: [a: b\n" => 'Upgrading file `upgrading.neon` of `acme/lib` is not valid NEON: %a%',
	];
	foreach ($errors as $content => $message) {
		$root = project('errors', ['acme/lib' => ['1.0.0.0', ['upgrading' => 'upgrading.neon']]], ['vendor/acme/lib/upgrading.neon' => $content]);
		Assert::exception(fn() => PackageDiscovery::discover(ProjectPackages::read($root)), ConfigurationException::class, $message);
	}

	$root = project('missing', ['acme/lib' => ['1.0.0.0', ['upgrading' => 'upgrading.neon']]], []);
	Assert::exception(fn() => PackageDiscovery::discover(ProjectPackages::read($root)), ConfigurationException::class, 'Upgrading file `upgrading.neon` of `acme/lib` does not exist.');
});


test('what a package says lies under the maps of the project where it says upgrading.libraries.packages, and only there', function () {
	$root = project(
		'run',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		['vendor/acme/lib/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n"],
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
	$map = ['replacedClasses' => ['App\Aged' => 'App\Fresh']];
	$resolution = $factory->resolve(new Config(decisions: ['upgrading' => ['libraries' => ['packages' => 'adopted', ...$map]]]), $root);
	$runner = $factory->createRunner($resolution, cache: false);
	$result = $runner->processCode("$root/f.php", $code);
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
	$layers = $resolution->resolvedConfig->decisions['upgrading.libraries.replacedClasses']->layers;
	Assert::same(['upgrading.neon of acme/lib', 'the configuration'], array_map(fn($layer) => $layer->origin?->describe(), $layers));

	// the map of the project alone does not bring the maps of the packages
	$resolution = $factory->resolve(new Config(decisions: ['upgrading' => ['libraries' => $map]]), $root);
	Assert::contains('use Acme\Lib\Old;', (string) $factory->createRunner($resolution, cache: false)->processCode("$root/f.php", $code)->output);

	// nothing of the project lets the packages in, so the package turns nothing on
	$rules = array_column($factory->resolve(new Config, $root)->resolvedConfig->rules, null, 'class');
	Assert::same('no preset or layer of the configuration names its decisions', $rules[DressCode\Rules\Upgrading\ReplacedClassesRule::class]->inactiveMessage);
});


test('dresscode config lists the upgrading files with the sections the version of the package has not reached yet', function () {
	$root = project(
		'config',
		['acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']]],
		['vendor/acme/lib/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplacedClasses: {}\n\nsince 3.3:\n\treplacedClasses: {}\n\nsince 4.0:\n\treplacedClasses: {}\n"],
	);

	$factory = new RunnerFactory;
	$resolution = $factory->resolve(new Config, $root);
	$printer = new ConfigPrinter($resolution->resolvedConfig, $resolution->upgradingData);
	Assert::match('%A%Packages   1 upgrading file%A%      acme/lib 3.2 %a%upgrading.neon of acme/lib, upgrading further to 3.3, 4.0%A%', $printer->print(new Console(colorDepth: ColorDepth::None)));
	Assert::same(
		[['source' => 'upgrading.neon of acme/lib', 'package' => 'acme/lib', 'version' => '3.2', 'unreached' => ['3.3', '4.0']]],
		json_decode($printer->printJson(), associative: true)['upgradingData'],
	);
});


test('what a package declares in its namespaces lies under the lists of the project, and DressCode knows that of the configurator of Symfony', function () {
	$root = project(
		'namespaces',
		[
			'acme/lib' => ['3.2.0.0', ['upgrading' => 'upgrading.neon']],
			'symfony/dependency-injection' => ['7.3.0.0', []],
		],
		[
			'vendor/acme/lib/upgrading.neon' => <<<'XX'
				package: acme/lib
				namespaces:
					functions: ['Acme\Lib\{helper}']

				XX,
		],
	);
	$factory = new RunnerFactory;
	$functions = $factory->resolve(new Config(namespaces: ['functions' => ['App\format']]), $root)->resolvedConfig->namespacedFunctions;
	Assert::same('the configuration', $functions['App\format']);
	Assert::same('upgrading.neon of acme/lib', $functions['Acme\Lib\helper']);
	Assert::same('DressCode for symfony/dependency-injection', $functions['Symfony\Component\DependencyInjection\Loader\Configurator\service']);
});
