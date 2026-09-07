<?php

namespace Acme\Shop;

class Order
{
	public const STATUS_PAID = 'paid';
	public const StatusPaid = 'paid';


	public function recalc(): void
	{
	}


	public function recalculate(): void
	{
	}


	public static function make(): static
	{
		return new static;
	}
}
