<?php

namespace Acme\Paths;

interface Path
{
	public function length(): int;

	public function isNullSafe(): bool;
}


abstract class Walker
{
	abstract protected function step(): void;


	public function walk(): void
	{
	}
}


trait Measured
{
	public function length(): int
	{
		return 0;
	}


	public function isNullSafe(): bool
	{
		return false;
	}
}


trait Labelled
{
	abstract public function label(): string;
}


trait Titled
{
	use Labelled;
}
