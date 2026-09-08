<?php

namespace App;

/**
 * @method void annotated()
 */
abstract class Magic
{
	public function __call(string $name, array $args): mixed
	{
	}
}
