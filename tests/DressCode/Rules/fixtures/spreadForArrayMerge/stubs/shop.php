<?php declare(strict_types=1);

namespace Acme\Shop;


/** @implements \IteratorAggregate<string, int> */
class Cart implements \IteratorAggregate
{
	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator([]);
	}
}
