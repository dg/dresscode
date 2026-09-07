<?php declare(strict_types=1);

namespace Acme\Shop;


class Order
{
	public static function make(int $id): self
	{
		return new self;
	}


	public function ship(int $days): void
	{
	}
}
