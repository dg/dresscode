<?php

namespace Nette\Forms;

class Form
{
	/** @deprecated use Form::Filled */
	public const FILLED = ':filled';
	public const Filled = ':filled';


	/** @deprecated use redrawControl() */
	public function invalidateControl(): void
	{
	}


	public function redrawControl(): void
	{
	}


	public static function make(): static
	{
		return new static;
	}
}
