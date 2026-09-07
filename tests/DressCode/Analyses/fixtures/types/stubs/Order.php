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


	/** @deprecated use make() */
	public static function build(): static
	{
		return new static;
	}


	/** @deprecated use $items */
	public array $legacy = [];

	/** @deprecated use $count */
	public static int $counter = 0;
}
