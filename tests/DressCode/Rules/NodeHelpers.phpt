<?php declare(strict_types=1);

use DressCode\Rules\NodeHelpers;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function expr(string $code): ExpressionNode
{
	return (new Parser)->parseExpression($code);
}


test('isBoolean()', function () {
	foreach ([
		'$a === $b', '$a < 1', '$a && $b', '$a or $b', '!$a', '(bool) $a', '($a == 1)', '$a instanceof B', 'isset($a)',
		'empty($a)', 'true', 'FALSE',
	] as $code) {
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


test('isWritten()', function () {
	$code = '<?php $w1 = 1; $w2 += 1; $w3++; [$w4, [$w5]] = f(); list($w6) = f(); unset($w7, $w8); global $w9;'
		. ' foreach ($r1 as $w10 => [$w11]) {} f(...$r2); echo $r3, $r4; $w12 = [$r5]; $w13[0] = 1; unset($w14[0]);'
		. ' $r6->a = 1; echo $r7[0];';
	foreach ((new Parser)->parse($code)->find(PhpSyntax\Nodes\Expression\VariableNode::class) as $variable) {
		Assert::same(str_starts_with($variable->text, '$w'), NodeHelpers::isWritten($variable), $variable->text);
	}
});
