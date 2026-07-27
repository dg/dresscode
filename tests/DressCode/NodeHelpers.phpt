<?php declare(strict_types=1);

use DressCode\NodeHelpers;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


function expr(string $code): ExpressionNode
{
	return (new Parser)->parseExpression($code);
}


test('isBoolean()', function () {
	foreach (['$a === $b', '$a < 1', '$a && $b', '$a or $b', '!$a', '(bool) $a', '($a == 1)', '$a instanceof B', 'isset($a)', 'empty($a)', 'true', 'FALSE'] as $code) {
		Assert::true(NodeHelpers::isBoolean(expr($code)), $code);
	}

	foreach (['$a', 'f()', '$a <=> $b', '$a ?? $b', '$a ? 1 : 2', '-$a', '(int) $a', 'null', '$a . $b', '$a = $b'] as $code) {
		Assert::false(NodeHelpers::isBoolean(expr($code)), $code);
	}
});


test('negate()', function () {
	$cases = [
		'$a === $b' => '$a !== $b',
		'$a != 1' => '$a == 1',
		'$a <> 1' => '$a == 1',
		'$a < 1' => '$a >= 1',
		'$a >= 1' => '$a < 1',
		'$a > $b' => '$a <= $b',
		'!$a' => '$a',
		'!($a && $b)' => '$a && $b',
		'true' => 'false',
		'FALSE' => 'true',
		'$a' => '!$a',
		'$a->b()' => '!$a->b()',
		'isset($a)' => '!isset($a)',
		'($a)' => '!($a)',
		'$a && $b' => '!($a && $b)',
		'$a instanceof B' => '!($a instanceof B)',
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
