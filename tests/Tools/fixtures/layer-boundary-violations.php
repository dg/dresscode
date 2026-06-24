<?php declare(strict_types=1);

namespace PhpSyntax\Fixtures;

use DressCode\Tools\PhpStan\LayerBoundaryRule;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use PhpParser\Node;


final class Violations
{
	public function run(Node $node): string
	{
		$name = new Own;
		return Strings::lower('a')
			. LayerBoundaryRule::class
			. Arrays::first([]);
	}
}


final class Own
{
}
