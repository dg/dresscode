<?php declare(strict_types=1);

namespace Acme\Time;


interface Clock
{
}


class SystemClock implements Clock
{
}


class Schedule
{
	public function __construct(?Clock $clock = null)
	{
	}


	public function load(?Clock $clock = null): void
	{
	}


	private function plan(?Clock $clock = null): void
	{
	}
}


abstract class Task
{
	abstract public function __construct(?Clock $clock = null);
}
