<?php declare(strict_types=1);

namespace Acme\Shop;


class Counter
{
	public int $count = 0;
	public float $total = 0.0;
	public ?int $limit = null;
	public string $code = 'a';
}
