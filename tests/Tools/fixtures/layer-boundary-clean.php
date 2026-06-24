<?php declare(strict_types=1);

namespace PhpSyntax\Fixtures;

use ArrayIterator;
use function strlen;


/** @implements \IteratorAggregate<int, int|string> */
final class Clean implements \IteratorAggregate
{
	public function getIterator(): \Iterator
	{
		return new ArrayIterator([strlen('x'), \PHP_VERSION_ID, self::class]);
	}


	public function throwUp(): never
	{
		throw new \RuntimeException(Sibling::class);
	}
}


final class Sibling
{
}
