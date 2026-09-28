<?php declare(strict_types=1);

namespace Acme\Shop;


final class Cart
{
	public ?int $total = null;
	public readonly ?int $id;
}


class Basket
{
	public ?int $total = null;
}


class Bag
{
	public function __get(string $name): mixed
	{
		return null;
	}


	public function __set(string $name, mixed $value): void
	{
	}
}
