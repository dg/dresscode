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
function check(string $content): array
{
	$root = str_replace('\\', '/', __DIR__) . '/../../temp/upgrading-tester';
	FileSystem::delete($root);
	FileSystem::write("$root/composer.json", '{"name": "acme/rules", "require-dev": {"acme/lib": "^3.0"}}');
	FileSystem::write("$root/vendor/composer/installed.json", json_encode(['packages' => [
		['name' => 'acme/lib', 'version' => 'v3.2.0', 'version_normalized' => '3.2.0.0', 'install-path' => '../acme/lib'],
	]], JSON_THROW_ON_ERROR));
	FileSystem::write("$root/upgrading/lib.neon", $content);
	return UpgradingTester::check("$root/upgrading/lib.neon", $root);
}


test('a sound file has no problems: what exists at the installed version, a withdrawn entry, a section not reached', function () {
	Assert::same([], check(<<<'XX'
		package: acme/lib
		group: deprecations

		since 3.0:
			replaced-classes:
				Acme\Lib\IControl: Acme\Lib\Control
				Acme\Lib\IOldest: Acme\Lib\IControl
			replaced-members:
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
			replaced-members:
				Acme\Lib\Form::Redraw: redraw

		since 3.1:
			replaced-members:
				Acme\Lib\Form::GONE: keep

		since 4.0:
			replaced-members:
				Acme\Lib\Form::redraw: refresh
		XX));
});


test('what the rules refuse is said with the rule, in whatever section it stands', function () {
	Assert::match(
		"replaced-members: The replacement 'items' of Acme\\Lib\\Form::legacy is not of its kind: %a%",
		check("package: acme/lib\ngroup: deprecations\n\nsince 9.0:\n\treplaced-members:\n\t\tAcme\\Lib\\Form::\$legacy: items\n")[0],
	);
	Assert::same(
		["Unknown rule 'replaced-things'."],
		check("package: acme/lib\ngroup: deprecations\n\nsince 3.0:\n\treplaced-things:\n\t\tA: B\n"),
	);
	Assert::same(
		["Upgrading file lib.neon: Unexpected key 'rules'; the file holds 'package', 'group' and sections 'since <version>'."],
		check("package: acme/lib\ngroup: deprecations\n\nrules: []\n"),
	);
	Assert::match('Upgrading file lib.neon: The package it is about is not installed in %a%', check("package: acme/other\ngroup: deprecations\n\nsince 1.0:\n\treplaced-classes: []\n")[0]);
});


test('a sentence of a forbidden map reads as the end of the message', function () {
	Assert::same([], check(<<<'XX'
		package: acme/lib
		group: deprecations

		since 3.0:
			forbidden-classes:
				Acme\Lib\IOldest: 'Acme\Lib\Control replaces it; call render() on it'
				Acme\Lib\IControl: INI files are read by Acme\Lib\Loader
		XX));
	Assert::same(
		[
			'forbidden-classes: The sentence of Acme\Lib\IOldest ends with a period.',
			'forbidden-classes: The sentence of Acme\Lib\IControl holds a backtick or a double quote.',
			'forbidden-classes: The sentence of Acme\Lib\IControl begins with a capital letter and no name.',
			"forbidden-classes: The sentence of Acme\\Lib\\Control says 'should'.",
			'forbidden-classes: The sentence of Acme\Lib\Form is longer than 160 characters.',
		],
		check(<<<'XX'
			package: acme/lib
			group: deprecations

			since 3.0:
				forbidden-classes:
					Acme\Lib\IOldest: there is no replacement.
					Acme\Lib\IControl: 'There is `render()`'
					Acme\Lib\Control: credentials should not be used
					Acme\Lib\Form: 'there is no replacement, and the text goes on and on about why, what the library did instead, where the manual tells more and what a project may write in the meantime'
			XX),
	);
});


test('a replacement that does not exist at the installed version, even at the end of what it leads to, and a circle are problems', function () {
	Assert::same(
		[
			'replaced-classes: Acme\Lib\IForm is replaced by Acme\Lib\Missing, which does not exist.',
			'replaced-classes: Acme\Lib\IOldest is replaced by Acme\Lib\IOlder, which does not exist, and neither does Acme\Lib\Missing, where it leads.',
			'replaced-classes: Acme\Lib\IOlder is replaced by Acme\Lib\Missing, which does not exist.',
			'replaced-classes: Acme\Lib\A is replaced by Acme\Lib\B, which leads back to it.',
			'replaced-classes: Acme\Lib\B is replaced by Acme\Lib\A, which leads back to it.',
			'replaced-members: Acme\Lib\Form::contains is replaced by \acme_missing, which does not exist.',
			'replaced-members: Acme\Lib\Form::FILLED is replaced by Missing, which does not exist.',
			'replaced-members: Acme\Lib\Form::$legacy is replaced by $missing, which does not exist.',
			'replaced-members: Acme\Lib\Form::make is replaced by Acme\Lib\Helpers::missing, which does not exist.',
			'replaced-members: Acme\Lib\Unknown::OLD is replaced by New, which does not exist.',
			'replaced-members: Acme\Lib\Form::OLDEST is replaced by Older, which does not exist, and neither does Acme\Lib\Form::Missing, where it leads.',
			'replaced-members: Acme\Lib\Form::Older is replaced by Missing, which does not exist.',
			'replaced-members: Acme\Lib\Form::redraw is replaced by Filled, which leads back to it.',
			'replaced-members: Acme\Lib\Form::Filled is replaced by redraw, which leads back to it.',
		],
		check(<<<'XX'
			package: acme/lib
			group: deprecations

			since 3.0:
				replaced-classes:
					Acme\Lib\IForm: Acme\Lib\Missing
					Acme\Lib\IOldest: Acme\Lib\IOlder
					Acme\Lib\IOlder: Acme\Lib\Missing
					Acme\Lib\A: Acme\Lib\B
					Acme\Lib\B: Acme\Lib\A
				replaced-members:
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


test('an attribute written instead of a member that does not exist is a problem', function () {
	Assert::same(
		['attribute-for-member: Acme\Lib\Form::$caption is replaced by the attribute Acme\Lib\Caption($value), which does not exist.'],
		check(<<<'XX'
			package: acme/lib
			group: deprecations

			since 3.0:
				attribute-for-member:
					Acme\Lib\Form::$caption: 'Acme\Lib\Caption($value)'
					Acme\Lib\IOldest: Acme\Lib\Form
			XX),
	);
});
