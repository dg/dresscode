<?php declare(strict_types=1);

/**
 * Over a configuration that exists init writes nothing and says how the code stands against it.
 */

use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/init.helpers.php';


$tabbed = "<?php\n\nclass A\n{\n\tpublic function b(): string\n\t{\n\t\treturn 'x' . 'y' . 'z';\n\t}\n}\n";


test(
	'a configuration that exists is not overwritten, and the report ends with that, not with a proposal as long as a file',
	function () use ($tabbed) {
		$root = createProject('existing', ['src/A.php' => $tabbed, 'dresscode.php.dist' => "<?php\n"]);
		// one that cannot be read is said with why, before anything is measured
		[$code, , $err] = runInit($root);
		Assert::same(3, $code);
		Assert::match('%A%`dresscode.php.dist` exists and cannot be read, so init has nothing to compare with: %a%', $err);
		Assert::same("<?php\n", file_get_contents("$root/dresscode.php.dist"));
		Assert::false(is_file("$root/dresscode.neon"));

		// two formats side by side are refused, as every other command refuses them
		$root = createProject(
			'existing-both',
			['src/A.php' => $tabbed, 'dresscode.neon' => "use: [nette]\n", 'dresscode.php' => "<?php\n"],
		);
		[$code, , $err] = runInit($root);
		Assert::same(3, $code);
		Assert::match('%A%Both `%a%dresscode.neon` and `%a%dresscode.php` exist; keep one of them.%A%', $err);
	},
);


test('over a configuration that exists, a decision the file leaves to the standard moves with its cost', function () use ($tabbed) {
	$root = createProject('moved', [
		'composer.json' => '{}',
		'src/A.php' => $tabbed,
		'src/B.php' => $tabbed,
		'dresscode.neon' => "use: perCs\npaths: [src]\n",
	]);
	[$code, $out] = runInit($root);
	Assert::same(0, $code);
	Assert::contains('indentation.unit: `dresscode.neon` has 4 spaces, a fix of it rewrites 2 files, init now writes tab', $out);
});


test('over a configuration that exists init says what it sets apart from the standard while the code holds to the standard', function () {
	$spaced = "<?php\n\nfunction run(): void\n{\n    echo 1;\n}\n";
	$root = createProject('existing-apart', [
		'composer.json' => '{}',
		'src/a.php' => $spaced,
		'src/b.php' => $spaced,
		'dresscode.neon' => "use: perCs\npaths: [src]\nindentation:\n\tunit: tab\n",
	]);
	[$code, $out] = runInit($root, ['--yes']);
	Assert::same(0, $code);
	Assert::match('%A%Moved      indentation.unit: `dresscode.neon` has tab, the code holds to %a% of the standard%A%', $out);
	Assert::notContains('Moved      nothing', $out);
	// as data, what moved is the point of the document
	$data = json_decode(runInit($root, ['--format', 'json'])[1], associative: true, flags: JSON_THROW_ON_ERROR);
	Assert::same(
		[[
			'kind' => 'heldToStandard',
			'path' => 'indentation.unit',
			'current' => 'tab',
			'measured' => '4 spaces',
			'cost' => null,
			'comment' => '',
		]],
		$data['shifts'],
	);

	// the files the configuration checks are compared, those of an override apart, which init says
	file_put_contents(
		"$root/dresscode.neon",
		"use: perCs\npaths: [src]\noverrides:\n\t-\n\t\tpaths: [src/b.php]\n\t\tindentation:\n\t\t\tunit: tab\n",
	);
	[$code, $out] = runInit($root, ['--yes']);
	Assert::same(0, $code);
	Assert::contains('comparing the code of', $out);
	Assert::contains(
		'Compared   the code with the standard and the decisions of `dresscode.neon`, over the files it checks, but 1 file an override of it gives decisions of their own',
		$out,
	);
});


test(
	'over a configuration that exists the code is compared with the presets a plugin it declares itself brings',
	function () use ($tabbed) {
		$root = createProject('existing-plugin', [
			'composer.json' => '{}',
			'src/A.php' => $tabbed,
			'style.neon' => "use: perCs\n",
			'dresscode.php' => <<<'XX'
				<?php declare(strict_types=1);

				final class InitComparedPlugin implements DressCode\Plugin
				{
					public function getManifest(): DressCode\PluginManifest
					{
						return new DressCode\PluginManifest(presets: ['review/style' => __DIR__ . '/style.neon']);
					}
				}

				return new DressCode\Config(use: [new InitComparedPlugin, 'review/style'], paths: ['src']);
				XX,
		]);
		// the class exists in this process alone, which the probes then run in
		[$code, $out, $err] = runInit($root, jobs: null);
		Assert::same('', $err);
		Assert::same(0, $code);
		Assert::contains('indentation.unit: `dresscode.php` has 4 spaces, a fix of it rewrites 1 file, init now writes tab', $out);
	},
);


test('over a configuration that exists init asks nothing in a terminal and takes no answer, as it writes nothing', function () {
	$asked = createProject('existing-asked', stragglers() + ['dresscode.neon' => "use: perCs\npaths: [src]\n"]);
	[$code, $out] = runInit($asked, [], "2\n");
	Assert::same(0, $code);
	Assert::notContains('Your choice', $out);
	Assert::notContains('Your call', $out);
	Assert::contains("\nMoved", $out);
	$moved = substr($out, (int) strpos($out, "\nMoved"));

	$piped = createProject('existing-piped', stragglers() + ['dresscode.neon' => "use: perCs\npaths: [src]\n"]);
	[, $out] = runInit($piped);
	Assert::same($moved, substr($out, (int) strpos($out, "\nMoved")));

	// one init cannot read is no reason to ask for the standard either, and --set has nothing to answer
	$broken = createProject('existing-broken', stragglers() + ['dresscode.neon' => "use: [\n"]);
	[$code, $out] = runInit($broken, [], "2\n");
	Assert::same(3, $code);
	Assert::notContains('which standard should it follow?', $out);
	[$code, , $err] = runInit($asked, ['--set', 'indentation.unit=tab']);
	Assert::same(3, $code);
	Assert::contains(
		'`--set` answers what init asks, and over `dresscode.neon`, which exists, it asks nothing; edit the file instead.',
		$err,
	);
});
