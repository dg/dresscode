<?php

namespace App;

class Base
{
	public function __construct(string $name, int $count = 0)
	{
	}


	public function render(string $template, array ...$params): string
	{
		return '';
	}


	protected function load(int $id): ?object
	{
		return null;
	}


	public function save(): void
	{
	}


	public function reset(): void
	{
	}


	public static function create(string $name): static
	{
		return new static($name);
	}


	public function wider(int $id): void
	{
	}
}


trait Renders
{
	public function render(string $template, array ...$params): string
	{
		return 'trait';
	}
}
