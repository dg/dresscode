<?php

namespace Acme\Shop;

class Order
{
	/** @deprecated use Order::StatusPaid */
	public const STATUS_PAID = 'paid';
	public const StatusPaid = 'paid';


	/** @deprecated use recalculate() */
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
