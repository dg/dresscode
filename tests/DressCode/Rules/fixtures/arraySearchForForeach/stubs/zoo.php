<?php declare(strict_types=1);

namespace Acme\Zoo;


/** @implements \IteratorAggregate<int, Animal> */
class Herd implements \IteratorAggregate
{
	public function getIterator(): \Iterator
	{
		return new \ArrayIterator([]);
	}
}


class Animal
{
	public function isAsleep(): bool
	{
		return false;
	}
}


class Keeper
{
	/** @var list<Animal> */
	public array $animals = [];
	public Herd $herd;

	/** @var iterable<Animal> */
	public iterable $visitors = [];


	public function feeds(Animal $animal, int $portion): bool
	{
		return $portion > 0;
	}


	public function weighs(Animal $animal, ?int &$weight): bool
	{
		$weight = 1;
		return true;
	}
}
