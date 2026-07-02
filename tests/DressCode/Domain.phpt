<?php declare(strict_types=1);

use DressCode\{ConfigurationException, Decision, Domain};
use DressCode\Domains\{Count, Map, Names, Shapes, Text, Words};
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('a shape is one of the known ones and never read', function () {
	$shapes = new Shapes(['compact' => ['foo($a, $b)', 'no space'], 'spaced' => ['foo ($a, $b)', 'a single space']]);
	Assert::same('spaced', $shapes->accept('foo ($a, $b)', 'spacing.call')->getShape());
	Assert::same('spaced', $shapes->accept('spaced', 'spacing.call')->getShape());
	Assert::exception(
		fn() => $shapes->accept('foo( $a )', 'spacing.call', keep: true),
		ConfigurationException::class,
		'Key `spacing.call` does not take `foo( $a )`; write `compact` (`"foo($a, $b)"`), `spaced` (`"foo ($a, $b)"`) or `keep`.',
	);
	Assert::exception(fn() => $shapes->accept(['foo($a, $b)'], 'spacing.call'), ConfigurationException::class);
});


test('a count is a number, a range in any notation, an open one, or a word', function () {
	$count = new Count(words: ['none' => 'no limit']);
	Assert::same([2, 2], $count->accept(2, 'x.y')->getCount());
	Assert::same([1, 2], $count->accept("1\u{2013}2", 'x.y')->getCount());
	Assert::same([0, 1], $count->accept('0-1', 'x.y')->getCount());
	Assert::same([1, null], $count->accept('1+', 'x.y')->getCount());
	Assert::same([1, null], $count->accept([1, null], 'x.y')->getCount());
	Assert::same('none', $count->accept('none', 'x.y')->getWord());
	Assert::exception(fn() => $count->accept('none', 'x.y')->getCount(), LogicException::class, 'The count is the word `none`.');
	Assert::exception(fn() => $count->accept('2-1', 'x.y'), ConfigurationException::class, "Key `x.y` does not take `2-1`, whose end lies below its start; write a count from `0`, a range such as `1\u{2013}2` or `1+` or `none`.");
	Assert::exception(fn() => $count->accept(-1, 'x.y'), ConfigurationException::class);
	Assert::exception(fn() => $count->accept('many', 'x.y'), ConfigurationException::class);
	Assert::same([PHP_INT_MAX, null], $count->accept(PHP_INT_MAX . '+', 'x.y')->getCount());
	Assert::same([7, 7], $count->accept('007-7', 'x.y')->getCount());
	Assert::exception(fn() => $count->accept('9223372036854775808+', 'x.y'), ConfigurationException::class);
	Assert::exception(fn() => $count->accept('1-999999999999999999999999999999', 'x.y'), ConfigurationException::class);

	$exact = new Count(1, 3, range: false);
	Assert::same([3, 3], $exact->accept(3, 'x.y')->getCount());
	Assert::exception(fn() => $exact->accept(4, 'x.y'), ConfigurationException::class, 'Key `x.y` does not take `4`, which lies outside 1 to 3; write a count from `1` to `3`.');
	Assert::exception(fn() => $exact->accept('1+', 'x.y'), ConfigurationException::class);
	Assert::exception(fn() => new Count(2, 1), InvalidArgumentException::class);
});


test('names are free, patterns are regular expressions, allowed ones are words', function () {
	Assert::same(['password'], new Names()->accept(['password'], 'x.y')->getNames());
	Assert::same([], new Names()->accept([], 'x.y')->getNames());
	Assert::exception(fn() => new Names()->accept('password', 'x.y'), ConfigurationException::class, 'Key `x.y` does not take `password`; write a list of names.');
	Assert::exception(fn() => new Names()->accept(['a', 'a'], 'x.y'), ConfigurationException::class);
	Assert::exception(fn() => new Names(regularExpressions: true)->accept(['~^__'], 'x.y'), ConfigurationException::class, 'Key `x.y` does not take `~^__`; write a regular expression.');

	$order = new Names(['traitUse' => '', 'method' => ''], ordered: true);
	Assert::same(['method', 'traitUse'], $order->accept(['method', 'traitUse'], 'x.y')->getNames());
	Assert::exception(fn() => $order->accept(['property'], 'x.y'), ConfigurationException::class, 'Key `x.y` does not take `property`; write `traitUse` or `method`.');
});


test('a map of a set of words takes those keys alone', function () {
	$map = new Map(new Count, wildcards: false, words: ['return' => '', 'yield' => '']);
	Assert::same([1, null], $map->accept(['return' => '1+'], 'blankLines.beforeStatement')->getEntries()['return']->getCount());
	Assert::same(['return' => 1], $map->accept(['return' => 1], 'blankLines.beforeStatement')->toWrittenData());
	Assert::exception(fn() => $map->accept(['echo' => 1], 'blankLines.beforeStatement'), ConfigurationException::class, 'Key `blankLines.beforeStatement` does not take `echo`; write `return` or `yield`.');
	Assert::same('a map of `return`, `yield` to a count from 0, a range `1–2`, an open one `1+`, an entry withdrawn with `keep`', $map->describe());
	Assert::exception(fn() => new Map(new Count, words: ['return' => '']), InvalidArgumentException::class);
});


test('a map maps names to values of its domain, keep withdraws an entry, never the whole', function () {
	$map = new Map(new Words(['backslashed' => '', 'imported' => '']));
	$value = $map->accept(['assert' => 'backslashed', 'strlen' => 'keep'], 'x.except');
	Assert::same('backslashed', $value->getEntries()['assert']->getWord());
	Assert::true($value->getEntries()['strlen']->isKept());
	Assert::same(['strlen' => 'imported'], $map->accept(['strlen' => 'imported'], 'x.except')->toWrittenData());
	Assert::same([], $map->accept([], 'x.except')->getEntries());
	Assert::exception(fn() => $map->accept('keep', 'x.except', keep: true), ConfigurationException::class, 'Key `x.except` does not take `keep`; write a map of names, an entry withdrawn by `name: keep`.');
	Assert::exception(fn() => $map->accept(['a'], 'x.except'), ConfigurationException::class);
	Assert::exception(fn() => $map->accept(['assert' => 'bare'], 'x.except'), ConfigurationException::class, 'Key `x.except.assert` does not take `bare`; write `backslashed` or `imported`.');
});


test('a map of names read in any letter case merges a name with the one spelled otherwise below', function () {
	$map = new Map(new Words(['backslashed' => '', 'imported' => '']), caseInsensitive: true);
	$merged = $map->merge($map->accept(['StrLen' => 'imported', 'count' => 'imported'], 'x.except'), $map->accept(['strlen' => 'keep'], 'x.except'));
	Assert::same(['count' => ['imported'], 'strlen' => 'keep'], $merged->toData());

	$merged = $map->merge($map->accept(['\Acme\Old' => 'imported'], 'x.except'), $map->accept(['acme\old' => 'keep'], 'x.except'));
	Assert::same(['acme\old' => 'keep'], $merged->toData());

	$map = new Map(new Words(['backslashed' => '', 'imported' => '']));
	$merged = $map->merge($map->accept(['FOO' => 'imported'], 'x.except'), $map->accept(['foo' => 'keep'], 'x.except'));
	Assert::same(['FOO' => ['imported'], 'foo' => 'keep'], $merged->toData());
});


test('a map of names read in any letter case names each of them once in one layer', function () {
	$map = new Map(new Words(['backslashed' => '', 'imported' => '']), caseInsensitive: true);
	Assert::exception(
		fn() => $map->accept(['OLD' => 'imported', '\old' => 'backslashed'], 'x.except'),
		DressCode\ConfigurationException::class,
		'Key `x.except` names `OLD` and `\old`, which are one name; keep one of them.',
	);
});


test('a getter of another domain is a mistake of the rule', function () {
	$value = new Shapes(['compact' => ['foo()', '']])->accept('foo()', 'x.y');
	Assert::exception(fn() => $value->getWord(), LogicException::class, 'The value is of DressCode\Domains\Shapes, not of DressCode\Domains\Words.');
	Assert::exception(fn() => new Shapes(['compact' => ['foo()', '']])->accept('keep', 'x.y', keep: true)->getShape(), LogicException::class, 'The value is `keep`; ask isKept() first.');
});


test('the helpers keep the vocabulary of states, placements and counts', function () {
	Assert::same(['forbidden' => 'never there'], Domain::state()->words);
	Assert::same(['forbidden', 'required'], array_keys(Domain::state('forbidden', 'required')->words));
	Assert::same(['sameLine', 'nextLine'], array_keys(Domain::placement()->words));
	Assert::same(['adopted'], array_keys(Domain::adopted()->words));
	Assert::same([1, 2], Domain::blankLines()->accept('1-2', 'blankLines.betweenMembers')->getCount());
	Assert::exception(fn() => Domain::state('allowed'), InvalidArgumentException::class, 'Word `allowed` is none of `forbidden`, `required`.');
});


test('a domain is data and prose', function () {
	Assert::same(
		['kind' => 'words', 'words' => ['LF' => 'LF'], 'tolerance' => false],
		new Words(['LF' => 'LF'])->toArray(),
	);
	Assert::same(
		[
			'kind' => 'map',
			'values' => ['kind' => 'count', 'min' => 0, 'max' => null, 'range' => true, 'words' => []],
			'wildcards' => true,
			'words' => null,
			'caseInsensitive' => false,
		],
		new Map(new Count)->toArray(),
	);
	Assert::same('`LF` (every line ends with LF); `CRLF`', new Words(['LF' => 'every line ends with LF', 'CRLF' => ''])->describe());
	Assert::same('compact "foo($a, $b)"; spaced "foo ($a, $b)" (one space)', new Shapes(['compact' => ['foo($a, $b)', ''], 'spaced' => ['foo ($a, $b)', 'one space']])->describe());
	Assert::same("a count from 0, a range `1\u{2013}2`, an open one `1+`; `none` (no limit)", new Count(words: ['none' => 'no limit'])->describe());
	Assert::same('an order of `traitUse`; `method`', new Names(['traitUse' => '', 'method' => ''], ordered: true)->describe());
});


test('a requirement takes keep, a parameter does not', function () {
	$requirement = new Decision('cleanup.is_null', Domain::state(), '`is_null($x)` is `$x === null`');
	Assert::true($requirement->isRequirement());
	Assert::true($requirement->accept('keep')->isKept());
	Assert::true($requirement->takesKeep());
	Assert::same('`forbidden` (never there); `keep`', $requirement->describeValues());

	$parameter = new Decision('correctness.debugOutputFunctions', new Names, 'The functions printing debug output', parameter: true, default: ['var_dump']);
	Assert::false($parameter->isRequirement());
	Assert::false($parameter->takesKeep());
	Assert::exception(fn() => $parameter->accept('keep'), ConfigurationException::class, 'Key `correctness.debugOutputFunctions` does not take `keep`; write a list of names.');

	// a map withdraws its entries one by one, never itself
	$map = new Decision('upgrading.replacedFunctions', new Map(new Text), 'A function written instead of another one');
	Assert::true($map->isRequirement());
	Assert::false($map->takesKeep());
	Assert::same('a map of names and patterns with `*` to a text, an entry withdrawn with `keep`', $map->describeValues());
	Assert::false($map->toArray()['keep']);

	Assert::exception(fn() => new Decision('calls', Domain::state(), ''), InvalidArgumentException::class, 'Decision path `calls` is not `section.key`, every link an identifier.');
	Assert::exception(fn() => new Decision('calls.is-null', Domain::state(), ''), InvalidArgumentException::class);
	Assert::noError(fn() => new Decision('correctness.__set_state', Domain::state(), ''));
	Assert::exception(fn() => new Decision('a.b', new Names, '', parameter: true, fact: true, default: []), InvalidArgumentException::class);
});
