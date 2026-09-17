<?php

namespace Lib;

class Form
{
}


abstract class Control
{
	public function getValue(): mixed
	{
		return null;
	}


	public function setValue(mixed $value): static
	{
		return $this;
	}


	public function getItems(): array
	{
		return [];
	}


	public function render(string $caption, array $attrs = [], bool $strict = false): string
	{
		return '';
	}


	public function attach(?Form $form, string ...$names): void
	{
	}


	final public function getName(): string
	{
		return '';
	}
}


interface Loader
{
	public function load(string $name, array $options = []): string;
}


abstract class Widget
{
	public function getParts(): (\Countable&\Traversable)|null
	{
		return null;
	}


	public function draw(string $caption, int $width, array $styles = ['color' => 'red']): void
	{
	}


	public function setCaption(string $caption): void
	{
	}


	public function setWidth(int $width): void
	{
	}
}


abstract class TestCase
{
	public static function provideCases(): iterable
	{
		return [];
	}


	public static function createSubject(): object
	{
		return new \stdClass;
	}


	public function describe(): string
	{
		return '';
	}
}
