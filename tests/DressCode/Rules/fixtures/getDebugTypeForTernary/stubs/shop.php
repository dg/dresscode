<?php declare(strict_types=1);

namespace Acme\Shop;


class Event
{
	/** @var string|array<string> */
	public string|array $payload = '';
	public mixed $context = null;
	public int $count = 0;
	public ?self $previous = null;
	public int|self $sequence = 0;
}
