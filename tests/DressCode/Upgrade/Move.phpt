<?php declare(strict_types=1);

use DressCode\Upgrade\Move;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('a move is major across a major version, or across a minor one below 1.0', function () {
	Assert::true(new Move('nette/utils', 'v3.2.10', 'v4.0.11')->isMajor());
	Assert::true(new Move('acme/mail', '4.0.0', '3.9.0')->isMajor());
	Assert::true(new Move('acme/beta', '0.3.1', '0.4.0')->isMajor());
	Assert::false(new Move('nette/utils', 'v4.0.1', 'v4.1.0')->isMajor());
	Assert::false(new Move('acme/beta', '0.3.1', '0.3.7')->isMajor());
});


test('the data know a move when they reach the version it moves to', function () {
	$known = ['nette/utils' => '4.1', 'acme/beta' => '0.3'];
	Assert::true(new Move('nette/utils', 'v3.2.10', 'v4.0.11')->isKnown($known));
	Assert::false(new Move('nette/utils', 'v4.1.0', 'v5.0.0')->isKnown($known));
	Assert::false(new Move('acme/beta', '0.3.1', '0.4.0')->isKnown($known));
	Assert::false(new Move('acme/mail', 'v2.0.0', 'v3.0.0')->isKnown($known));
});
