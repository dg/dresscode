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
	public function load(?Clock $clock = null): void
	{
	}
}
