<?php

// the declarations the types of tests/DressCode/Rules/upgrading-interplay.phpt are read from

namespace Old;

interface Router
{
	public const Secured = 1;
	public const OneWay = 2;
}


class Legacy
{
	public const OldName = 1;
}


class Mailer
{
	/** @deprecated use transmit() */
	public function post(string $to): void
	{
	}


	public function send(string $to, bool $now = false): void
	{
	}


	public function transmit(string $to): void
	{
	}


	public function transmitNow(string $to): void
	{
	}
}


namespace Fresh;

interface Router
{
	public const OneWay = 2;
}


class Modern
{
	public const NewName = 1;
}


namespace Fresh\Attribute;

#[\Attribute]
class Route
{
	public function __construct(?string $path = null, ?string $name = null)
	{
	}
}


namespace Old\Bus;

/** @deprecated write the attribute AsHandler */
interface Handler
{
}
