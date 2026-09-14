<?php declare(strict_types=1);

namespace Acme\Lib;

interface Control
{
}


class Form
{
	public const Filled = ':filled';

	public array $items = [];


	public function redraw(): void
	{
	}
}


/**
 * @method static int getCount()
 */
class Helpers
{
	public static function create(): Form
	{
		return new Form;
	}
}
