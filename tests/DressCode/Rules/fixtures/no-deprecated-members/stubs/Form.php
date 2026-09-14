<?php

namespace Nette\Forms;

class Form
{
	/** @deprecated use Form::Filled */
	public const FILLED = ':filled';
	public const Filled = ':filled';

	/** @deprecated use Form::Equal */
	public const EQUAL = ':equal';
	public const Equal = ':equal';

	/** @deprecated */
	public const OLD = 'old';

	/** @deprecated use Nette\Forms\Validator::validateEmail() */
	public const EMAIL = ':email';


	/** @deprecated use redrawControl() */
	public function invalidateControl(): void
	{
	}


	public function redrawControl(): void
	{
	}


	/** @deprecated use Helpers::create() instead */
	public static function make(): static
	{
		return new static;
	}
}


class Helpers
{
	public static function create(): Form
	{
		return new Form;
	}
}
