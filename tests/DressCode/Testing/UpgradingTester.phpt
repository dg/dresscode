<?php declare(strict_types=1);

use DressCode\Testing\UpgradingTester;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/fixtures/upgrading/library.php';


/**
 * The problems of the file in a project that has acme/lib 3.2 installed, its classes being those of the fixture.
 * @return list<string>
 */
function check(string $content, string $composer = '{"name": "acme/rules", "require-dev": {"acme/lib": "^3.0"}}'): array
{
	$root = createTempDir('upgrading-tester');
	FileSystem::write("$root/composer.json", $composer);
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		['name' => 'acme/lib', 'version' => 'v3.2.0', 'version_normalized' => '3.2.0.0', 'install-path' => '../acme/lib'],
	]], JSON_THROW_ON_ERROR));
	FileSystem::write("$root/upgrading/lib.neon", $content);
	return UpgradingTester::collectProblems("$root/upgrading/lib.neon", $root);
}


test('a sound file has no problems: what exists at the installed version, a withdrawn entry, a section not reached', function () {
	Assert::same([], check(<<<'XX'
		package: acme/lib

		since 3.0:
			replacedClasses:
				Acme\Lib\IControl: Acme\Lib\Control
				Acme\Lib\IOldest: Acme\Lib\IControl
			replacedMembers:
				Acme\Lib\Form::FILLED: Filled
				'Acme\Lib\Form::invalidate()': redraw()
				Acme\Lib\Form::$legacy: $items
				Acme\Lib\Form::make: Acme\Lib\Helpers::create
				Acme\Lib\Form::contains: \str_contains
				Acme\Lib\IControl::OLD: Acme\Lib\Form::Filled
				Acme\Lib\Removed::create: Acme\Lib\Helpers::create
				Acme\Lib\Form::GONE: Missing
				Acme\Lib\Form::REDRAW: Redraw
				Acme\Lib\Helpers::count: getCount

		since 3.0.5:
			replacedMembers:
				Acme\Lib\Form::Redraw: redraw

		since 3.1:
			replacedMembers:
				Acme\Lib\Form::GONE: keep

		since 4.0:
			replacedMembers:
				Acme\Lib\Form::redraw: refresh
		XX));
});


test('what the maps refuse is said with the map, in whatever section it stands', function () {
	Assert::match(
		'`since 9.0`: `replacedMembers`: The replacement `items` of `Acme\\Lib\\Form::legacy` is not of its kind: %a%',
		check("package: acme/lib\n\nsince 9.0:\n\treplacedMembers:\n\t\tAcme\\Lib\\Form::\$legacy: items\n")[0],
	);
	Assert::same(
		['`since 3.0`: Unknown map `replacedThings`; an upgrading file holds `replacedClasses`, `replacedFunctions`, `replacedMembers`, `replacedCalls`, `forbiddenClasses`, `forbiddenFunctions`, `forbiddenConstants`, `forbiddenMembers`, `attributeForAnnotation`, `attributeForMember` and the maps of the rules of its package by the paths of their decisions.'],
		check("package: acme/lib\n\nsince 3.0:\n\treplacedThings:\n\t\tA: B\n"),
	);
	Assert::same(
		['Upgrading file `lib.neon`: Unexpected key `rules`; the file holds `package`, `namespaces` and sections `since <version>`.'],
		check("package: acme/lib\n\nrules: []\n"),
	);
	Assert::match(
		'The package `acme/other` that `lib.neon` is about is not installed in `%a%`, so nothing of it can be checked; require `acme/other` in `require-dev`.',
		check("package: acme/other\n\nsince 1.0:\n\treplacedClasses: []\n")[0],
	);
});


#[DressCode\RuleInfo(DressCode\Stage::Structure)]
final class UpgradingTesterRetiredRule extends DressCode\NodeRule
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


test('a file turns on only a requirement of the rules of its package, with a word it takes', function () {
	$root = createTempDir('upgrading-tester-rules');
	FileSystem::write("$root/composer.json", '{"name": "acme/rules", "require-dev": {"acme/lib": "^3.0"}}');
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		['name' => 'acme/lib', 'version' => 'v3.2.0', 'version_normalized' => '3.2.0.0', 'install-path' => '../acme/lib'],
	]], JSON_THROW_ON_ERROR));
	$problems = function (string $section) use ($root): array {
		FileSystem::write("$root/upgrading/lib.neon", "package: acme/lib\n\nsince 3.0:\n$section\n");
		return UpgradingTester::collectProblems("$root/upgrading/lib.neon", $root, [UpgradingTesterRetiredRule::class]);
	};

	Assert::same([], $problems("\tacme.retired: required"));
	Assert::same(
		['`since 3.0`: `upgrading.php.backticks`: An upgrading file turns on only a requirement of the rules of its package.'],
		$problems("\tupgrading.php.backticks: forbidden"),
	);
	Assert::match('%a%`acme.retired`%a%', $problems("\tacme.retired: forbidden")[0]);
});


test('a section a later one overwrites is checked as it is written', function () {
	foreach (['Acme\Lib\Control', 'keep'] as $later) {
		Assert::match(
			'`since 3.0`: `replacedClasses`: %a%',
			check("package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\IControl: [invalid]\n\nsince 3.1:\n\treplacedClasses:\n\t\tAcme\\Lib\\IControl: $later\n")[0],
		);
	}
});


test('a sentence of a forbidden map reads as the end of the message', function () {
	Assert::same([], check(<<<'XX'
		package: acme/lib

		since 3.0:
			forbiddenClasses:
				Acme\Lib\IOldest: 'Acme\Lib\Control replaces it; call render() on it'
				Acme\Lib\IControl: INI files are read by Acme\Lib\Loader
				Acme\Lib\IRenderer: 'call `render()` on it, or `renderTo()` where the output goes to a stream, which the old interface wrote into its own buffer whatever the caller asked of it first'
		XX));
	Assert::same(
		[
			'`since 3.0`: `forbiddenClasses`: The sentence of `Acme\Lib\IOldest` ends with a period.',
			'`since 3.0`: `forbiddenClasses`: The sentence of `Acme\Lib\IControl` holds a double quote.',
			'`since 3.0`: `forbiddenClasses`: The sentence of `Acme\Lib\IControl` begins with a capital letter, but not with a name.',
			'`since 3.0`: `forbiddenClasses`: The sentence of `Acme\Lib\Control` says `should`.',
			'`since 3.0`: `forbiddenClasses`: The sentence of `Acme\Lib\Form` is longer than 160 characters.',
		],
		check(<<<'XX'
			package: acme/lib

			since 3.0:
				forbiddenClasses:
					Acme\Lib\IOldest: there is no replacement.
					Acme\Lib\IControl: 'There is "render()"'
					Acme\Lib\Control: credentials should not be used
					Acme\Lib\Form: 'there is no replacement, and the text goes on and on about why, what the library did instead, where the manual tells more and what a project may write in the meantime'
			XX),
	);
});


test(
	'a replacement that does not exist at the installed version, even at the end of what it leads to, and a circle are problems',
	function () {
		Assert::same(
			[
				'`replacedClasses`: `Acme\Lib\IForm` is replaced by `Acme\Lib\Missing`, which does not exist.',
				'`replacedClasses`: `Acme\Lib\IOldest` is replaced by `Acme\Lib\IOlder`, which does not exist, and neither does `Acme\Lib\Missing`, where it leads.',
				'`replacedClasses`: `Acme\Lib\IOlder` is replaced by `Acme\Lib\Missing`, which does not exist.',
				'`replacedClasses`: `Acme\Lib\A` is replaced by `Acme\Lib\B`, whose replacements lead back to `Acme\Lib\A`.',
				'`replacedClasses`: `Acme\Lib\B` is replaced by `Acme\Lib\A`, whose replacements lead back to `Acme\Lib\B`.',
				'`replacedClasses`: `Acme\Lib\C` is replaced by `Acme\Lib\A`, whose replacements go round in a circle.',
				'`replacedMembers`: `Acme\Lib\Form::contains` is replaced by `\acme_missing`, which does not exist.',
				'`replacedMembers`: `Acme\Lib\Form::FILLED` is replaced by `Missing`, which does not exist.',
				'`replacedMembers`: `Acme\Lib\Form::$legacy` is replaced by `$missing`, which does not exist.',
				'`replacedMembers`: `Acme\Lib\Form::make` is replaced by `Acme\Lib\Helpers::missing`, which does not exist.',
				'`replacedMembers`: `Acme\Lib\Unknown::OLD` is replaced by `New`, which does not exist.',
				'`replacedMembers`: `Acme\Lib\Form::OLDEST` is replaced by `Older`, which does not exist, and neither does `Acme\Lib\Form::Missing`, where it leads.',
				'`replacedMembers`: `Acme\Lib\Form::Older` is replaced by `Missing`, which does not exist.',
				'`replacedMembers`: `Acme\Lib\Form::redraw` is replaced by `Filled`, whose replacements lead back to `Acme\Lib\Form::redraw`.',
				'`replacedMembers`: `Acme\Lib\Form::Filled` is replaced by `redraw`, whose replacements lead back to `Acme\Lib\Form::Filled`.',
			],
			check(<<<'XX'
				package: acme/lib

				since 3.0:
					replacedClasses:
						Acme\Lib\IForm: Acme\Lib\Missing
						Acme\Lib\IOldest: Acme\Lib\IOlder
						Acme\Lib\IOlder: Acme\Lib\Missing
						Acme\Lib\A: Acme\Lib\B
						Acme\Lib\B: Acme\Lib\A
						Acme\Lib\C: Acme\Lib\A
					replacedMembers:
						Acme\Lib\Form::FILLED: Missing
						Acme\Lib\Form::$legacy: $missing
						Acme\Lib\Form::make: Acme\Lib\Helpers::missing
						Acme\Lib\Form::contains: \acme_missing
						Acme\Lib\Unknown::OLD: New
						Acme\Lib\Form::OLDEST: Older
						Acme\Lib\Form::Older: Missing
						Acme\Lib\Form::redraw: Filled
						Acme\Lib\Form::Filled: redraw
				XX),
		);
	},
);


test('an attribute written instead of a member that does not exist is a problem', function () {
	Assert::same(
		['`attributeForMember`: `Acme\Lib\Form::$caption` is replaced by the attribute `Acme\Lib\Caption($value)`, which does not exist.'],
		check(<<<'XX'
			package: acme/lib

			since 3.0:
				attributeForMember:
					Acme\Lib\Form::$caption: 'Acme\Lib\Caption($value)'
					Acme\Lib\IOldest: Acme\Lib\Form
			XX),
	);
});


test('a function replaced by a static method that does not exist is a problem', function () {
	Assert::same(
		['`replacedFunctions`: `acme_old` is replaced by `Acme\Lib\Helpers::missing`, which does not exist.'],
		check(<<<'XX'
			package: acme/lib

			since 3.0:
				replacedFunctions:
					acme_make: Acme\Lib\Helpers::create
					acme_old: Acme\Lib\Helpers::missing
			XX),
	);
});


test('a key under extra.dresscode that DressCode does not read is a problem', function () {
	$file = "package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\IControl: Acme\\Lib\\Control\n";
	Assert::same(
		[],
		check(
			$file,
			'{"name": "acme/rules", "extra": {"dresscode": {"upgrading-dir": "upgrading", "packages": ["acme/lib"], "plugin": "Acme\\\\Rules"}}}',
		),
	);
	Assert::same([], check($file, '{"name": "acme/rules", "extra": {"branch-alias": {}}}'));
	Assert::same(
		['Package `acme/rules`: `extra.dresscode` in its `composer.json` holds the key `upgrades`, which DressCode does not know; it reads `upgrading-dir`, `packages`, `plugin`.'],
		check($file, '{"name": "acme/rules", "extra": {"dresscode": {"upgrading-dir": "upgrading", "upgrades": []}}}'),
	);
});


test('the packages a package lists are exactly those its upgrading files are about', function () {
	$root = createTempDir('upgrading-packages');
	FileSystem::write("$root/upgrading/lib.neon", "package: acme/lib\n");
	FileSystem::write("$root/upgrading/tools.neon", "package: acme/tools\n");
	$problems = function (string $packages) use ($root): array {
		FileSystem::write(
			"$root/composer.json",
			'{"name": "acme/rules", "extra": {"dresscode": {"upgrading-dir": "upgrading", "packages": ' . $packages . '}}}',
		);
		return UpgradingTester::collectPackageProblems($root);
	};

	Assert::same([], $problems('["acme/lib", "acme/tools"]'));
	Assert::same([], $problems('["acme/"]'));
	Assert::same(
		[
			'Package `acme/rules`: `extra.dresscode.packages` in its `composer.json` does not list `acme/tools`, which `upgrading/tools.neon` is about.',
			'Package `acme/rules`: `extra.dresscode.packages` in its `composer.json` lists `acme/gone`, which no upgrading file is about.',
		],
		$problems('["acme/lib", "acme/gone"]'),
	);
});


test('a forbidden map of a package must give every entry a sentence', function () {
	$problems = check(<<<'XX'
		package: acme/lib

		since 3.0:
			forbiddenClasses:
				- Acme\Lib\IOldest
				Acme\Lib\IControl: there is no replacement
			forbiddenMembers:
				- Acme\Lib\Form::$legacy
			forbiddenFunctions:
				- acme_old
				acme_make: 'use `Acme\Lib\Helpers::create()`'
		XX);
	Assert::contains(
		'`since 3.0`: `forbiddenClasses`: The entry `Acme\Lib\IOldest` must give a sentence saying what to write instead.',
		$problems,
	);
	Assert::contains(
		'`since 3.0`: `forbiddenMembers`: The entry `Acme\Lib\Form::$legacy` must give a sentence saying what to write instead.',
		$problems,
	);
	Assert::contains(
		'`since 3.0`: `forbiddenFunctions`: The entry `acme_old` must give a sentence saying what to write instead.',
		$problems,
	);
	Assert::count(3, array_filter($problems, fn(string $problem) => str_contains($problem, 'must give a sentence')));
});


test('what the data of a package forbid is deprecated where the installed code still has it and removed where not', function () {
	$root = createTempDir('upgrading-retirement');
	FileSystem::write(
		"$root/composer.json",
		'{"name": "acme/rules", "require-dev": {"acme/lib": "^3.0"}, "extra": {"dresscode": {"upgrading-dir": "upgrading"}}}',
	);
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		['name' => 'acme/lib', 'version' => 'v3.2.0', 'version_normalized' => '3.2.0.0', 'install-path' => '../acme/lib'],
	]], JSON_THROW_ON_ERROR));
	FileSystem::write("$root/upgrading/lib.neon", <<<'XX'
		package: acme/lib

		since 3.0:
			forbiddenMembers:
				Acme\Lib\Form::redraw: there is no replacement
				Acme\Lib\Form::refresh: there is no replacement
			forbiddenClasses:
				Acme\Lib\Gone: there is no replacement
			forbiddenFunctions:
				Acme\Lib\acme_kept: there is no replacement
				acme_gone: there is no replacement
		XX);
	$code = <<<'XX'
		<?php

		namespace Acme\Lib {
			class Form
			{
				public function redraw(): void {}
			}

			function acme_kept(): void {}
		}

		namespace App {
			function run(\Acme\Lib\Form $form): void
			{
				$form->redraw();
				$form->refresh();
				new \Acme\Lib\Gone;
				\Acme\Lib\acme_kept();
				acme_gone();
				var_dump(1);
			}
		}
		XX;

	// the map of the project itself forbids
	$decisions = ['upgrading' => ['api' => ['forbiddenFunctions' => ['var_dump' => 'drop the call']]]];
	$messages = array_map(fn($violation) => $violation->message, UpgradingTester::runSample($code, $root, $decisions)->violations);
	Assert::same(
		[
			'Method `Acme\Lib\Form::redraw()` is deprecated: there is no replacement, since acme/lib 3.0.',
			'Method `Acme\Lib\Form::refresh()` is removed: there is no replacement, since acme/lib 3.0.',
			'Class `Acme\Lib\Gone` is removed: there is no replacement, since acme/lib 3.0.',
			'Function `Acme\Lib\acme_kept()` is deprecated: there is no replacement, since acme/lib 3.0.',
			'Function `acme_gone()` is removed: there is no replacement, since acme/lib 3.0.',
			'Function `var_dump()` is forbidden: drop the call.',
		],
		$messages,
	);
});


test('a sample reads its classes from its own code, not from those of a sample run before, and leaves no file', function () {
	$root = createTempDir('upgrading-sample');
	FileSystem::write(
		"$root/composer.json",
		'{"name": "acme/rules", "require-dev": {"acme/lib": "^3.0"}, "extra": {"dresscode": {"upgrading-dir": "upgrading"}}}',
	);
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		['name' => 'acme/lib', 'version' => 'v3.2.0', 'version_normalized' => '3.2.0.0', 'install-path' => '../acme/lib'],
	]], JSON_THROW_ON_ERROR));
	FileSystem::write("$root/upgrading/lib.neon", <<<'XX'
		package: acme/lib

		since 3.0:
			forbiddenMembers:
				Acme\Lib\Control::$limit: there is no replacement
		XX);
	$implementing = <<<'XX'
		<?php

		namespace Acme\Lib {
			interface Control {}
		}

		namespace App {
			class Job implements \Acme\Lib\Control {}
		}
		XX;
	$own = <<<'XX'
		<?php

		namespace Acme\Lib {
			interface Control {}
		}

		namespace App {
			class Job {}

			function run(Job $job): mixed
			{
				return $job->limit;
			}
		}
		XX;
	$run = fn(string $code, string $name) => UpgradingTester::runSample($code, $root, name: $name)->violations;

	// the class of the sample implements nothing, whichever sample ran before
	$run($implementing, 'a');
	Assert::same([], $run($own, 'b'));
	$run($implementing, 'c');
	Assert::same([], $run($own, 'b'));
	Assert::same([], glob("$root/temp/*"));
});
