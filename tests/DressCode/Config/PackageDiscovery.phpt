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
 * says under extra, and the files of the project and of the packages. The project requires every package in the
 * version installed, unless $root says its own require.
 * @param  array<string, array{string, array<string, mixed>}>  $packages  name → [normalized version, extra.dresscode]
 * @param  array<string, string>  $files  path → content
 * @param  array<string, mixed>  $root  what the composer.json of the project says besides its name
 */
function project(string $name, array $packages, array $files, array $root = []): string
{
	$dir = createTempDir($name);
	$root += ['require' => array_map(fn(array $package) => $package[0], $packages)];
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

	FileSystem::write(
		"$dir/vendor/composer/installed.json",
		json_encode(['packages' => $installed, 'dev' => true, 'dev-package-names' => []], JSON_THROW_ON_ERROR),
	);
	foreach ($files as $file => $content) {
		FileSystem::write("$dir/$file", $content);
	}

	return $dir;
}


test('the upgrading data a package ships applies up to the lowest version required, of the root package whole', function () {
	$root = project(
		'versions',
		['acme/lib' => ['3.2.0.0', ['upgrading-dir' => 'upgrading']]],
		[
			'vendor/acme/lib/upgrading/upgrading.neon' => <<<'XX'
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
			'data/root.neon' => <<<'XX'
				package: app/project

				since 9.9:
					replacedClasses:
						App\Old: App\Renamed
				XX,
		],
		['require' => ['acme/lib' => '^3.2'], 'extra' => ['dresscode' => ['upgrading-dir' => 'data']]],
	);

	$packages = PackageDiscovery::discover(ProjectPackages::read($root));
	Assert::same([], $packages->warnings);
	Assert::same([], $packages->plugins);
	Assert::same(
		['data/root.neon of app/project', 'upgrading/upgrading.neon of acme/lib'],
		array_map(fn($data) => $data->layer->describe(), $packages->upgradingData),
	);

	// every section of the root package, whose version says nothing
	Assert::same([], $packages->upgradingData[0]->unreached);
	Assert::same(['replacedClasses' => ['App\Old' => 'App\Renamed']], $packages->upgradingData[0]->maps);

	// the sections up to 3.2 in the order of their versions, a later one having the last word on a key; 3.5 is not
	// reached, but ^3.2 allows it, so what it replaces is reported
	Assert::same(
		[
			'replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\RenamedAgain', 'Acme\Lib\Older' => 'Acme\Lib\Renamed'],
			'replacedMembers' => ['Acme\Lib\Renamed::old' => 'renamed'],
			'forbiddenClasses' => ['Acme\Lib\Newest' => 'replaced by `Acme\Lib\Future`'],
		],
		$packages->upgradingData[1]->maps,
	);
	Assert::same(['forbiddenClasses' => ['Acme\Lib\Newest' => '3.5']], $packages->upgradingData[1]->retirements);
	Assert::same(['3.5'], $packages->upgradingData[1]->unreached);
	Assert::same(['3.5'], $packages->upgradingData[1]->reported);

	// a package the project does not require has no lower bound, whatever is installed: no section is reached, every
	// one reported
	FileSystem::write("$root/composer.json", json_encode(['name' => 'app/project'], JSON_THROW_ON_ERROR));
	$data = PackageDiscovery::discover(ProjectPackages::read($root))->upgradingData[0];
	Assert::same(
		[
			'forbiddenClasses' => [
				'Acme\Lib\Old' => 'replaced by `Acme\Lib\RenamedAgain`',
				'Acme\Lib\Older' => 'replaced by `Acme\Lib\Renamed`',
				'Acme\Lib\Newest' => 'replaced by `Acme\Lib\Future`',
			],
			'forbiddenMembers' => ['Acme\Lib\Renamed::old' => 'replaced by `renamed`'],
		],
		$data->maps,
	);
	Assert::same(
		[
			'forbiddenClasses' => [
				'Acme\Lib\Old' => '3.2',
				'Acme\Lib\Older' => '3.0',
				'Acme\Lib\Newest' => '3.5',
			],
			'forbiddenMembers' => ['Acme\Lib\Renamed::old' => '3.0'],
		],
		$data->retirements,
	);
	Assert::same(['3.0', '3.2', '3.5'], $data->unreached);
	Assert::same(['3.0', '3.2', '3.5'], $data->reported);
});


test('a section above the lowest version is reported where the constraint reaches it, unless the code is rewritten', function () {
	$root = project(
		'reported',
		['acme/lib' => ['3.4.0.0', ['upgrading-dir' => 'upgrading']]],
		[
			'vendor/acme/lib/upgrading/upgrading.neon' => <<<'XX'
				package: acme/lib

				since 3.0:
					replacedClasses:
						Acme\Lib\Old: Acme\Lib\Renamed

				since 4.0:
					forbiddenClasses:
						Acme\Lib\Old: use `Acme\Lib\Renamed` instead.
						- Acme\Lib\Gone
					forbiddenFunctions:
						Acme\Lib\helper: keep
					replacedFunctions:
						Acme\Lib\old_helper: {write: Acme\Lib\helper, from: '3.0'}
					replacedCalls:
						'Acme\Lib\Order::pay(true)': payNow()
						'Acme\Lib\Order::flush()': {write: removed, from: '3.5'}
						'Acme\Lib\format($value, true)': 'Acme\Lib\render($value)'

				since 5.0:
					replacedClasses:
						Acme\Lib\Later: Acme\Lib\Latest
				XX,
		],
		['require' => ['acme/lib' => '^3.2 || ^4.0']],
	);

	// what 3.0 rewrites is not reported again, an entry right from a version the code reaches is rewritten, a call keeps
	// the shape of its arguments, and 5.0 lies above the constraint
	[$data] = PackageDiscovery::discover(ProjectPackages::read($root))->upgradingData;
	Assert::same(
		[
			'replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed'],
			'replacedFunctions' => ['Acme\Lib\old_helper' => 'Acme\Lib\helper'],
			'forbiddenClasses' => ['Acme\Lib\Gone' => null],
			'forbiddenMembers' => ['Acme\Lib\Order::pay(true)' => 'replaced', 'Acme\Lib\Order::flush()' => 'does nothing'],
			'forbiddenFunctions' => ['Acme\Lib\format' => 'replaced by `Acme\Lib\render($value)`'],
		],
		$data->maps,
	);
	Assert::same(['4.0', '5.0'], $data->unreached);
	Assert::same(['4.0'], $data->reported);

	// the sentence ends with the version that retired the entry, where the data are laid; one that does nothing since
	// a version earlier than its section says that one
	Assert::same(
		[
			'replacedClasses' => ['Acme\Lib\Old' => 'Acme\Lib\Renamed'],
			'replacedFunctions' => ['Acme\Lib\old_helper' => 'Acme\Lib\helper'],
			'forbiddenClasses' => ['Acme\Lib\Gone' => 'since acme/lib 4.0'],
			'forbiddenMembers' => [
				'Acme\Lib\Order::pay(true)' => 'replaced, since acme/lib 4.0',
				'Acme\Lib\Order::flush()' => 'does nothing, since acme/lib 3.5',
			],
			'forbiddenFunctions' => ['Acme\Lib\format' => 'replaced by `Acme\Lib\render($value)`, since acme/lib 4.0'],
		],
		$data->buildDecisions(fn(string $map) => $map),
	);
});


test(
	'a later section has the last word on an entry, and a value NEON reads as an entity stays one for the grammar of the map',
	function () {
		$root = project(
			'keep',
			['acme/lib' => ['3.2.0.0', ['upgrading-dir' => 'upgrading']]],
			[
				'vendor/acme/lib/upgrading/upgrading.neon' => <<<'XX'
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
						replacedMembers:
							Acme\Lib\Order::pay: settle

					since 3.11:
						replacedMembers: []
					XX,
			],
		);

		// 3.10 is newer than 3.2, and a section holding nothing leaves nothing to upgrade further
		[$data] = PackageDiscovery::discover(ProjectPackages::read($root))->upgradingData;
		Assert::equal(
			['replacedMembers' => ['Acme\Lib\Order::OLD' => 'keep', 'Acme\Lib\Order::$paid' => new Nette\Neon\Entity('isPaid')]],
			$data->maps,
		);
		Assert::same(['3.10', '4'], $data->unreached);
	},
);


test('upgrading data for a package that is not installed is left out, and a package may carry the data of another', function () {
	$root = project(
		'carrier',
		[
			'acme/lib' => ['3.2.0.0', []],
			'acme/rules' => ['9999999-dev', ['upgrading-dir' => 'upgrading']],
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

	// the directory upgrading-dir names stands for every NEON file in it, in the order of their names
	$root = project(
		'carrier-directory',
		[
			'acme/lib' => ['3.2.0.0', []],
			'acme/tools' => ['1.0.0.0', []],
			'acme/rules' => ['9999999-dev', ['upgrading-dir' => 'upgrading']],
		],
		[
			'vendor/acme/rules/upgrading/tools.neon' => "package: acme/tools\n",
			'vendor/acme/rules/upgrading/lib.neon' => "package: acme/lib\n",
			'vendor/acme/rules/upgrading/notes.txt' => '',
		],
	);
	Assert::same(
		['upgrading/lib.neon of acme/rules', 'upgrading/tools.neon of acme/rules'],
		array_map(fn($data) => $data->layer->describe(), PackageDiscovery::discover(ProjectPackages::read($root))->upgradingData),
	);
});


test('a package the project requires itself is measured by the lowest version its constraint allows, not the installed one', function () {
	$root = project(
		'required',
		['acme/lib' => ['3.4.0.0', ['upgrading-dir' => 'upgrading']]],
		['vendor/acme/lib/upgrading/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n\nsince 3.3:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Later\n"],
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
	$resolution = $factory->resolve(
		new Config(
			targets: ['acme/lib' => '3.3', 'acme/ghost' => '1.0', 'app/project' => '2.0'],
			decisions: ['upgrading' => ['packages' => true]],
		),
		$root,
	);
	$runner = $factory->createRunner($resolution, cache: false);
	Assert::same(
		"<?php\n\nnamespace App;\n\nnew \\Acme\\Lib\\Later;\n",
		$runner->processCode("$root/f.php", "<?php\n\nnamespace App;\n\nnew \\Acme\\Lib\\Old;\n")->output,
	);
	Assert::same([
		'The configuration names package `acme/ghost` in `targets`, but it is not installed; skipped.' => null,
		'The configuration names package `app/project` in `targets`, but that is the project itself, whose code is any version of it; skipped.' => null,
	], $resolution->warnings);
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
	$root = project(
		'plugin-admitted',
		['acme/rules' => ['1.0.0.0', ['plugin' => PackagePlugin::class]]],
		['style.neon' => "decisions:\n\tfile: {bom: forbidden}\n"],
	);
	PackagePlugin::$preset = "$root/style.neon";
	$factory = new RunnerFactory;

	$resolution = $factory->resolve(new Config, $root);
	Assert::same(
		['Package `acme/rules` brings the plugin `PackagePlugin`, which loads only where `use` names the package or the plugin; skipped.' => null],
		$resolution->warnings,
	);
	Assert::exception(
		fn() => (new RunnerFactory)->resolve(new Config(use: ['acme/style']), $root),
		ConfigurationException::class,
		'Unknown preset `acme/style`.',
	);

	foreach (['acme/rules', PackagePlugin::class] as $name) {
		$resolution = (new RunnerFactory)->resolve(new Config(use: [$name, 'acme/style']), $root);
		Assert::same([], $resolution->warnings, $name);
	}

	$own = project(
		'plugin-own',
		[],
		['style.neon' => "decisions:\n\tfile: {bom: forbidden}\n"],
		['extra' => ['dresscode' => ['plugin' => PackagePlugin::class]]],
	);
	PackagePlugin::$preset = "$own/style.neon";
	Assert::same([], (new RunnerFactory)->resolve(new Config(use: ['acme/style']), $own)->warnings);
});


test(
	'a package of the vendor of the core is named in use without the vendor too, and one sharing its name with a preset is an error',
	function () {
		$root = project(
			'plugin-vendor',
			['dresscode/upgrade-acme' => ['1.0.0.0', ['plugin' => PackagePlugin::class]]],
			['style.neon' => "decisions:\n\tfile: {bom: forbidden}\n"],
		);
		PackagePlugin::$preset = "$root/style.neon";
		foreach (['dresscode/upgrade-acme', 'upgrade-acme'] as $name) {
			$resolution = (new RunnerFactory)->resolve(new Config(use: [$name, 'acme/style']), $root);
			Assert::same([], $resolution->warnings, $name);
		}

		$shared = project('plugin-shared', ['acme/style' => ['1.0.0.0', ['plugin' => PackagePlugin::class]]], ['style.neon' => '']);
		PackagePlugin::$preset = "$shared/style.neon";
		Assert::exception(
			fn() => (new RunnerFactory)->resolve(new Config(use: ['acme/style']), $shared),
			ConfigurationException::class,
			'Package `acme/style` brings a plugin and a preset has the same name, so `use` cannot tell which one it names.',
		);
	},
);


test('a key under extra.dresscode that DressCode does not read is a warning of the run', function () {
	$root = project('unknown-key', ['acme/lib' => ['1.0.0.0', ['upgrades' => []]]], []);
	$factory = new RunnerFactory;
	$resolution = $factory->resolve(new Config, $root);
	Assert::same(
		['Package `acme/lib`: `extra.dresscode` in its `composer.json` holds the key `upgrades`, which this DressCode does not know; skipped.' => null],
		$resolution->warnings,
	);
});


test(
	'an upgrading file that turns a map on instead of giving its entries, names an unknown key or is missing is an error naming it',
	function () {
		$errors = [
			"package: acme/lib\n\nsince 1.0:\n\treplacedClasses: true\n"
				=> 'Upgrading file `upgrading/upgrading.neon` of `acme/lib`: The map `replacedClasses` in `since 1.0` must be a map of entries; a package turns on only a rule of its own plugin, by the path of its decision and a word.',
			"package: acme/lib\n\nrules:\n\treplacedClasses: []\n"
				=> 'Upgrading file `upgrading/upgrading.neon` of `acme/lib`: Unexpected key `rules`; the file holds `package`, `namespaces` and sections `since <version>`.',
			"since 1.0:\n\treplacedClasses: []\n"
				=> 'Upgrading file `upgrading/upgrading.neon` of `acme/lib`: The key `package` must name the package the sections are versions of, as `vendor/name`, or `php`.',
			"package: acme/lib\n\nsince 1.0: [a, b]\n"
				=> 'Upgrading file `upgrading/upgrading.neon` of `acme/lib`: The section `since 1.0` must be a map of maps to their entries.',
			"package: acme/lib\n\nsince 1.0:\n\treplacedClasses: [a: b\n" => 'Upgrading file `upgrading/upgrading.neon` of `acme/lib` is not valid NEON: %a%',
			"package: acme/lib\n\nsince 1.0:\n\treplacedClasses:\n\t\tOld: {write: New, from: 8}\n"
				=> "Upgrading file `upgrading/upgrading.neon` of `acme/lib`: `from` of the entry `Old` of `replacedClasses` in `since 1.0` must be a version, as `'8.0'`.",
		];
		foreach ($errors as $content => $message) {
			$root = project(
				'errors',
				['acme/lib' => ['1.0.0.0', ['upgrading-dir' => 'upgrading']]],
				['vendor/acme/lib/upgrading/upgrading.neon' => $content],
			);
			Assert::exception(fn() => PackageDiscovery::discover(ProjectPackages::read($root)), ConfigurationException::class, $message);
		}

		$root = project('missing', ['acme/lib' => ['1.0.0.0', ['upgrading-dir' => 'upgrading']]], []);
		Assert::exception(
			fn() => PackageDiscovery::discover(ProjectPackages::read($root)),
			ConfigurationException::class,
			'Package `acme/lib`: `extra.dresscode.upgrading-dir` in its `composer.json` names no directory of the package.',
		);
	},
);


#[DressCode\RuleInfo(DressCode\Stage::Structure)]
final class PackageRetiredRule extends DressCode\NodeRule
{
	public static function getDecisions(): array
	{
		return [new DressCode\Decision('acme.retired', DressCode\Domain::state('required'), 'What the library retired, rewritten')];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}


	public function enter(PhpSyntax\Node|PhpSyntax\Token $node, DressCode\RuleContext $context): void
	{
	}
}


final class PackageRulesPlugin implements DressCode\Plugin
{
	public function getManifest(): DressCode\PluginManifest
	{
		return new DressCode\PluginManifest(rules: [PackageRetiredRule::class], section: 'acme');
	}
}


test('an upgrading file turns on a rule of the plugin of its package where its section is reached, and no other rule', function () {
	$run = function (string $section, array $decisions, ?string $only = null): ?bool {
		$root = project(
			'plugin-rule',
			['acme/lib' => ['2.0.0.0', ['upgrading-dir' => 'upgrading', 'plugin' => PackageRulesPlugin::class]]],
			['vendor/acme/lib/upgrading/upgrading.neon' => "package: acme/lib\n\n$section\n"],
		);
		return (new RunnerFactory)->resolve(
			new Config(use: ['acme/lib'], decisions: $decisions),
			$root,
			only: $only === null ? null : [$only],
		)
			->resolvedConfig
			->findRule(PackageRetiredRule::class)
			?->isActive();
	};
	$packages = ['upgrading' => ['packages' => true]];

	Assert::true($run("since 2.0:\n\tacme.retired: required", $packages));
	Assert::true($run("since 2.0:\n\tacme.retired: required", $packages, only: 'acme/lib'));
	Assert::false($run("since 2.0:\n\tacme.retired: required", []));
	Assert::false($run("since 2.0:\n\tacme.retired: required", $packages + ['acme' => ['retired' => 'keep']]));
	// the lowest version does not reach it, and a requirement has nothing to report without its fix
	Assert::false($run("since 2.1:\n\tacme.retired: required", $packages));
	Assert::exception(
		fn() => $run("since 2.0:\n\tupgrading.php.backticks: forbidden", $packages),
		ConfigurationException::class,
		'`upgrading/upgrading.neon` of `acme/lib` turns on `upgrading.php.backticks`, which is no rule of the plugin of its package.',
	);
});


test('a function of PHP the data forbid is deprecated or removed as the target has it, without the types too', function () {
	$root = project(
		'retired-php',
		['acme/lib' => ['3.2.0.0', ['upgrading-dir' => 'upgrading']]],
		['vendor/acme/lib/upgrading/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\tforbiddenFunctions:\n\t\tstrftime: use `IntlDateFormatter`\n\t\timap_open: there is no replacement\n\t\tacme_format: there is no replacement\n"],
		['require' => ['php' => '>=8.4', 'acme/lib' => '^3.0']],
	);

	$factory = new RunnerFactory;
	$resolution = $factory->resolve(new Config(decisions: ['upgrading' => ['packages' => true]]), $root);
	$result = $factory->createRunner($resolution, cache: false)
		->processCode("$root/f.php", "<?php\nstrftime('%Y');\nimap_open('x', 'u', 'p');\nacme_format();\n");
	Assert::same(
		[
			'Function `strftime()` is deprecated: use `IntlDateFormatter`, since acme/lib 3.0.',
			'Function `imap_open()` is removed: there is no replacement, since acme/lib 3.0.',
			'Function `acme_format()` is forbidden: there is no replacement, since acme/lib 3.0.',
		],
		array_map(fn($violation) => $violation->message, $result->violations),
	);
});


test('what a package says lies under the maps of the project where it says upgrading.packages, and only there', function () {
	$root = project(
		'run',
		['acme/lib' => ['3.2.0.0', ['upgrading-dir' => 'upgrading']]],
		['vendor/acme/lib/upgrading/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\Old: Acme\\Lib\\Renamed\n"],
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
	$resolution = $factory->resolve(new Config(decisions: ['upgrading' => ['packages' => true, 'api' => $map]]), $root);
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
	$layers = $resolution->resolvedConfig->decisions['upgrading.api.replacedClasses']->layers;
	Assert::same(
		['upgrading/upgrading.neon of acme/lib', 'the configuration'],
		array_map(fn($layer) => $layer->origin?->describe(), $layers),
	);

	// the map of the project alone does not bring the maps of the packages
	$resolution = $factory->resolve(new Config(decisions: ['upgrading' => ['api' => $map]]), $root);
	Assert::contains(
		'use Acme\Lib\Old;',
		(string) $factory->createRunner($resolution, cache: false)->processCode("$root/f.php", $code)->output,
	);

	// nothing of the project lets the packages in, so the package turns nothing on
	$rules = array_column($factory->resolve(new Config, $root)->resolvedConfig->rules, null, 'class');
	Assert::same(
		'no preset or layer of the configuration names its decisions',
		$rules[DressCode\Rules\Upgrading\ReplacedClassesRule::class]->inactiveMessage,
	);
});


test('dresscode config lists the upgrading data with the sections the lowest version has not reached yet', function () {
	$root = project(
		'config',
		['acme/lib' => ['3.2.0.0', ['upgrading-dir' => 'upgrading']]],
		['vendor/acme/lib/upgrading/upgrading.neon' => "package: acme/lib\n\nsince 3.0:\n\treplacedClasses: {A: B}\n\nsince 3.3:\n\treplacedClasses: {C: D}\n\nsince 4.0:\n\treplacedClasses: {E: F}\n"],
		['require' => ['acme/lib' => '^3.2']],
	);

	$factory = new RunnerFactory;
	$resolution = $factory->resolve(new Config, $root);
	$printer = new ConfigPrinter($resolution->resolvedConfig, $resolution->upgradingData);
	Assert::match(
		'%A%Packages   2 with upgrading data%A%      acme/lib 3.2 %a%upgrading/upgrading.neon of acme/lib, upgrading further to 3.3 (reported), 4.0
      php 8.0 %a%php.neon of php, upgrading further to 8.1 (reported), %A%',
		$printer->print(new Console(colorDepth: ColorDepth::None)),
	);
	Assert::same(
		[
			'source' => 'upgrading/upgrading.neon of acme/lib',
			'package' => 'acme/lib',
			'version' => '3.2',
			'unreached' => ['3.3', '4.0'],
			'reported' => ['3.3'],
		],
		json_decode($printer->printJson(), associative: true)['upgradingData'][0],
	);
});


test('what a package declares in its namespaces lies under the lists of the project', function () {
	$root = project(
		'namespaces',
		[
			'acme/lib' => ['3.2.0.0', ['upgrading-dir' => 'upgrading']],
		],
		[
			'vendor/acme/lib/upgrading/upgrading.neon' => <<<'XX'
				package: acme/lib
				namespaces:
					functions: ['Acme\Lib\{helper}']

				XX,
		],
	);
	$factory = new RunnerFactory;
	$functions = $factory->resolve(new Config(namespaces: ['functions' => ['App\format']]), $root)->resolvedConfig->namespacedFunctions;
	Assert::same('the configuration', $functions['App\format']);
	Assert::same('upgrading/upgrading.neon of acme/lib', $functions['Acme\Lib\helper']);
});
