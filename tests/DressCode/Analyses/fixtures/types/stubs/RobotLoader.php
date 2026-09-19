<?php

namespace Nette\Loaders;

interface Loader
{
}


trait Caching
{
}


class RobotLoader implements Loader
{
	use Caching;

	public const RetryLimit = 3;

	public ?string $tempDirectory = null;

	public static int $count = 0;


	public function __construct(?string $tempDirectory = null, bool $autoRebuild = false)
	{
	}


	public function getCacheKey(string ...$parts): array
	{
		return [];
	}


	public static function create(): static
	{
		return new static;
	}


	public function __get(string $name): mixed
	{
		return null;
	}
}


/** @deprecated use Nette\Loaders\RobotLoader */
class OldLoader extends RobotLoader
{
}


class Unrelated
{
	public function getCacheKey(int $depth): array
	{
		return [];
	}
}
