<?php

namespace Acme;

class Container
{
	public function addUpload(string $name, ?string $label = null, bool $multiple = false): Control
	{
		return new Control;
	}


	public function addMultiUpload(string $name, ?string $label = null): Control
	{
		return new Control;
	}
}


class Control
{
	public function getOption(string $key): mixed
	{
		return null;
	}


	public function isMethod(string $method): bool
	{
		return true;
	}


	public function getRouters(): array
	{
		return [];
	}


	public function setFeature(Feature $feature, bool $state = true): static
	{
		return $this;
	}
}


class MyControl extends Control
{
}


enum Feature
{
	case StrictParsing;
}


class Cache
{
	public function load(string $key, ?callable $fallback = null, ?callable $generator = null, mixed ...$dependencies): mixed
	{
		return null;
	}
}


class Callback
{
}


class Structure
{
	public function __construct(array $shape = [], bool $strict = false)
	{
	}
}


class Mapper
{
	public function __construct(iterable $iterator, callable $callback)
	{
	}
}


class SortedMapper extends Mapper
{
}


class Iterables
{
	public static function map(iterable $iterable, callable $transformer): iterable
	{
		return $iterable;
	}
}


class Dumper
{
	public function dump(mixed $var): string
	{
		return '';
	}
}
