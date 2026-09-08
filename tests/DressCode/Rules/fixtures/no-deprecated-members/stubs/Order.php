<?php

namespace Acme\Shop;

class Order
{
	/** @deprecated use Order::StatusPaid */
	public const STATUS_PAID = 'paid';
	public const StatusPaid = 'paid';

	/** @deprecated use Order::StatusOpen */
	public const STATUS_OPEN = 'open';
	public const StatusOpen = 'open';

	/** @deprecated */
	public const OLD = 'old';

	/** @deprecated use Acme\Shop\Validator::validateEmail() */
	public const EMAIL = ':email';


	/** @deprecated use recalculate() */
	public function recalc(): void
	{
	}


	public function recalculate(): void
	{
	}


	/** @deprecated use "check()" instead */
	public function verify(string $rule): bool
	{
		return true;
	}


	/** @deprecated use "checkWith()" instead */
	public function verifyWith(string $rule): bool
	{
		return true;
	}


	public function check(string $rule, object $context): bool
	{
		return true;
	}


	public function checkWith(string $rule, ?object $context = null): bool
	{
		return true;
	}


	/** @deprecated use STATUS_CLOSED instead */
	public const CLOSED = 'closed';


	/** @deprecated use Helpers::create() instead */
	public static function make(): static
	{
		return new static;
	}
}


class Helpers
{
	public static function create(): Order
	{
		return new Order;
	}
}
