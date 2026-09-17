<?php

namespace Acme;

class Playlist
{
	public function addTrack(string $name, ?string $label = null, bool $multiple = false): Settings
	{
		return new Settings;
	}


	public function addAlbum(string $name, ?string $label = null): Settings
	{
		return new Settings;
	}


	public function copyTrack(string $name): void
	{
	}
}


class Editor
{
	public function __construct(Playlist $playlist)
	{
	}


	public function copyTrack(string $name): void
	{
	}
}


class Settings
{
	public array $listeners = [];


	public function getOption(string $key): mixed
	{
		return null;
	}


	public function hasMode(string $method): bool
	{
		return true;
	}


	public function getEntries(): array
	{
		return [];
	}


	public function setFlag(Flag $flag, bool $state = true): static
	{
		return $this;
	}


	public function notify(mixed ...$args): void
	{
	}


	public function describe(string $separator): string
	{
		return '';
	}
}


class MySettings extends Settings
{
}


enum Flag
{
	case Strict;
}


class Store
{
	public function load(string $key, ?callable $miss = null, ?callable $factory = null, mixed ...$tags): mixed
	{
		return null;
	}
}


class Invoker
{
}


class Layout
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


	public static function create(array $options): Layout
	{
		return new Layout;
	}
}


class SortedMapper extends Mapper
{
}


class Sequence
{
	public static function map(iterable $iterable, callable $transformer): iterable
	{
		return $iterable;
	}


	public static function invokeAll(iterable $callbacks, mixed ...$args): void
	{
	}
}


class Formatter
{
	public function render(mixed $var): string
	{
		return '';
	}


	public static function describe(Settings $settings, string $separator): string
	{
		return '';
	}
}


class Range
{
	public function __construct(int|array|null $exactly = null, ?int $min = null, ?int $max = null)
	{
	}
}


class Pick
{
	public function __construct(?array $options = null, ?array $values = null, bool $multiple = false)
	{
	}
}


class KernelCase
{
	protected static ?object $container = null;


	protected static function getContainer(): object
	{
		return new \stdClass;
	}
}
