<?php declare(strict_types=1);

namespace DressCode\Fixtures;

use Nette\Utils\Strings;


final class Outside
{
	public function run(): string
	{
		return Strings::lower('a');
	}
}
