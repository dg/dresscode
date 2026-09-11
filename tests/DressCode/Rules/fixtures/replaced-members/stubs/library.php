<?php

namespace Acme\Shop;

class Order
{
	public const StatusPaid = 'paid';

	public array $items = [];

	public static int $count = 0;


	public function recalculate(): void
	{
	}


	public function attached(object $parent): void
	{
	}


	public static function create(): static
	{
		return new static;
	}


	public static function build(): static
	{
		return new static;
	}
}


class Helpers
{
	public const Renewed = 'renewed';


	public static function create(): Order
	{
		return new Order;
	}
}


namespace Acme\Text;

class Search
{
}


class Finder
{
	public static function find(): static
	{
		return new static;
	}
}
