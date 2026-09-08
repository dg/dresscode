<?php

namespace Acme\Shop;

class Order
{
	public function getTotal(): float
	{
		return 0.0;
	}


	public static function fromArray(array $data): self
	{
		return new self;
	}
}
