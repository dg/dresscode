<?php declare(strict_types=1);

use DressCode\Rules\NodeHelpers;
use PhpSyntax\Builder;
use PhpSyntax\Nodes\ExpressionNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function expr(string $code): ExpressionNode
{
	return (new Builder)->expression($code);
}


test('negate()', function () {
	$cases = [
		'$a === $b' => '$a !== $b',
		'$a != 1' => '$a == 1',
		'$a <> 1' => '$a == 1',
		'$a < 1' => '!($a < 1)', // an ordering is not flipped: against NAN both orderings are false
		'$a >= 1' => '!($a >= 1)',
		'$a > $b' => '!($a > $b)',
		'!$a' => '$a',
		'!($a && $b)' => '$a && $b',
		'true' => 'false',
		'FALSE' => 'true',
		'$a' => '!$a',
		'$a->b()' => '!$a->b()',
		'isset($a)' => '!isset($a)',
		'($a)' => '!($a)',
		'$a && $b' => '!($a && $b)',
		'$a instanceof B' => '!$a instanceof B', // instanceof binds tighter than !
		'$a <=> $b' => '!($a <=> $b)',
	];
	foreach ($cases as $code => $expected) {
		$original = expr($code);
		$negated = NodeHelpers::negate($original);
		Assert::same($expected, (string) $negated, $code);
		Assert::null($negated->parent);
		Assert::same($code, (string) $original);
	}
});
