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
		'`replacedMembers`: The replacement `items` of `Acme\\Lib\\Form::legacy` is not of its kind: %a%',
		check("package: acme/lib\n\nsince 9.0:\n\treplacedMembers:\n\t\tAcme\\Lib\\Form::\$legacy: items\n")[0],
	);
	Assert::same(
		['Unknown map `replacedThings`; an upgrading file holds `replacedClasses`, `replacedFunctions`, `replacedMembers`, `forbiddenFunctions` and the maps of the rules of its package by the paths of their decisions.'],
		check("package: acme/lib\n\nsince 3.0:\n\treplacedThings:\n\t\tA: B\n"),
	);
	Assert::same(
		['Upgrading file `lib.neon`: Unexpected key `rules`; the file holds `package`, `namespaces` and sections `since <version>`.'],
		check("package: acme/lib\n\nrules: []\n"),
	);
	Assert::match('The package `acme/other` that `lib.neon` is about is not installed in `%a%`, so nothing of it can be checked; require `acme/other` in `require-dev`.', check("package: acme/other\n\nsince 1.0:\n\treplacedClasses: []\n")[0]);
});


test('a sentence of a forbidden map reads as the end of the message', function () {
	Assert::same([], check(<<<'XX'
		package: acme/lib

		since 3.0:
			forbiddenFunctions:
				acme_oldest: 'Acme\Lib\Control::render() replaces it'
				acme_read_ini: INI files are read by Acme\Lib\Loader
				acme_render: 'call `render()` on the control, or `renderTo()` where the output goes to a stream, which the old function wrote into its own buffer whatever the caller asked'
		XX));
	Assert::same(
		[
			'`forbiddenFunctions`: The sentence of `acme_oldest` ends with a period.',
			'`forbiddenFunctions`: The sentence of `acme_render` holds a double quote.',
			'`forbiddenFunctions`: The sentence of `acme_render` begins with a capital letter, but not with a name.',
			'`forbiddenFunctions`: The sentence of `acme_connect` says `should`.',
			'`forbiddenFunctions`: The sentence of `acme_format` is longer than 160 characters.',
		],
		check(<<<'XX'
			package: acme/lib

			since 3.0:
				forbiddenFunctions:
					acme_oldest: there is no replacement.
					acme_render: 'There is "render()"'
					acme_connect: credentials should not be used
					acme_format: 'there is no replacement, and the text goes on and on about why, what the library did instead, where the manual tells more and what a project may write in the meantime'
			XX),
	);
});


test('a replacement that does not exist at the installed version, even at the end of what it leads to, and a circle are problems', function () {
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
});


test('a key under extra.dresscode that DressCode does not read is a problem', function () {
	$file = "package: acme/lib\n\nsince 3.0:\n\treplacedClasses:\n\t\tAcme\\Lib\\IControl: Acme\\Lib\\Control\n";
	Assert::same([], check($file, '{"name": "acme/rules", "extra": {"dresscode": {"upgrading": ["upgrading/lib.neon"], "plugin": "Acme\\\\Rules"}}}'));
	Assert::same([], check($file, '{"name": "acme/rules", "extra": {"branch-alias": {}}}'));
	Assert::same(
		['Package `acme/rules`: `extra.dresscode` in its `composer.json` holds the key `upgrades`, which DressCode does not know; it reads `upgrading`, `plugin`.'],
		check($file, '{"name": "acme/rules", "extra": {"dresscode": {"upgrading": ["upgrading/lib.neon"], "upgrades": []}}}'),
	);
});


test('a forbidden map of a package must give every entry a sentence', function () {
	$problems = check(<<<'XX'
		package: acme/lib

		since 3.0:
			forbiddenFunctions:
				acme_old:
				acme_make: 'use `Acme\Lib\Helpers::create()`'
		XX);
	Assert::contains('`forbiddenFunctions`: The entry `acme_old` must give a sentence saying what to write instead.', $problems);
	Assert::count(1, array_filter($problems, fn(string $problem) => str_contains($problem, 'must give a sentence')));
});
