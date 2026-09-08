<?php

namespace App;

interface Renderable
{
	public function render(): string;
}

abstract class Base
{
	public function run(): void
	{
	}


	private function hidden(): void
	{
	}


	public static function make(): static
	{
	}
}
