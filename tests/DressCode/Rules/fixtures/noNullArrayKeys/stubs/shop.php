<?php declare(strict_types=1);

namespace Acme\Shop;


/** @implements \ArrayAccess<string, int> */
class Cart implements \ArrayAccess
{
	public function offsetExists(mixed $offset): bool
	{
		return false;
	}


	public function offsetGet(mixed $offset): int
	{
		return 0;
	}


	public function offsetSet(mixed $offset, mixed $value): void
	{
	}


	public function offsetUnset(mixed $offset): void
	{
	}
}
