<?php declare(strict_types=1);

use DressCode\{Claim, Line};
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('a count of blank lines is at least zero, a range has two ends in order', function () {
	Assert::same(0, new Claim(blankLines: 0)->blankLines);
	Assert::same([1, null], new Claim(blankLinesBelowComment: [1, null])->blankLinesBelowComment);
	Assert::same([0, 2], Claim::blankLines([0, 2])->blankLines);

	Assert::exception(
		fn() => new Claim(blankLines: -1),
		InvalidArgumentException::class,
		'The `blankLines` of a claim must be a count of at least zero or a range `[min, max]` with `min` at most `max` and null for an open end, `-1` given.',
	);
	Assert::exception(fn() => new Claim(blankLines: [2, 1]), InvalidArgumentException::class);
	Assert::exception(fn() => new Claim(line: Line::Next, blankLinesBelowComment: [-1, 1]), InvalidArgumentException::class);
	Assert::exception(fn() => new Claim(blankLines: [1]), InvalidArgumentException::class); // @phpstan-ignore argument.type (the check is the point)
	Assert::exception(fn() => new Claim(blankLines: [1, 2, 3]), InvalidArgumentException::class);
	Assert::exception(fn() => new Claim(blankLines: [null, 2]), InvalidArgumentException::class); // @phpstan-ignore argument.type (the check is the point)
	Assert::exception(fn() => new Claim(blankLines: ['a', 1]), InvalidArgumentException::class); // @phpstan-ignore argument.type (the check is the point)
	Assert::exception(fn() => new Claim(blankLines: [0, 'a']), InvalidArgumentException::class); // @phpstan-ignore argument.type (the check is the point)
});
