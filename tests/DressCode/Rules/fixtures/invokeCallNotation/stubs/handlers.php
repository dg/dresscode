<?php declare(strict_types=1);

namespace Acme\Bus;


class Handler
{
	public function __invoke(int ...$args): int
	{
		return 0;
	}
}


class Audit
{
	public function __invoke(int ...$args): int
	{
		return 1;
	}
}


class Plain
{
	public function handle(): void
	{
	}
}


class Registry
{
	public Handler $handler;


	public function find(): Handler
	{
		return new Handler;
	}


	public static function make(): Handler
	{
		return new Handler;
	}
}
