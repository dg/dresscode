<?php

namespace Acme\Shop;

final class Order
{
	public int $total = 0;
}

class Cart
{
}

#[\AllowDynamicProperties]
final class Bag
{
}

final class Basket
{
	public function __set(string $name, mixed $value): void
	{
	}
}

interface Item
{
}
