<?php

namespace Nette\Forms;

class Form
{
	public const Filled = ':filled';

	public array $items = [];

	public static int $count = 0;


	public function redrawControl(): void
	{
	}


	public function attached(object $parent): void
	{
	}


	public static function create(): static
	{
		return new static;
	}


	public static function build(): static
	{
		return new static;
	}
}


class Helpers
{
	public const Renewed = 'renewed';


	public static function create(): Form
	{
		return new Form;
	}
}


namespace Nette\Utils;

class Strings
{
}


class Finder
{
	public static function find(): static
	{
		return new static;
	}
}
