<?php

namespace Acme\Console;

abstract class Command
{
	protected static ?string $defaultName = null;
	protected static string $defaultDescription = '';
}


#[\Attribute(\Attribute::TARGET_CLASS)]
class AsCommand
{
	public function __construct(string $name, ?string $description = null, bool $hidden = false)
	{
	}
}


namespace Acme\Bus;

interface Handler
{
}


interface Subscriber extends Handler
{
}


#[\Attribute(\Attribute::TARGET_CLASS)]
class AsHandler
{
	public function __construct(?string $bus = null)
	{
	}
}


namespace Acme\Orm;

abstract class Model
{
	public bool $timestamps = true;

	protected array $fillable = [];


	public function getRouteKey(): string
	{
		return 'id';
	}
}


#[\Attribute(\Attribute::TARGET_CLASS)]
class WithoutTimestamps
{
}


#[\Attribute(\Attribute::TARGET_CLASS)]
class RouteKey
{
	public function __construct(string $name)
	{
	}
}


#[\Attribute(\Attribute::TARGET_CLASS)]
class Fillable
{
	public function __construct(array $columns)
	{
	}
}
