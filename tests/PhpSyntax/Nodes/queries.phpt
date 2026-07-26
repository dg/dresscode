<?php declare(strict_types=1);

use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function parseStatement(string $code): ExpressionStatementNode
{
	$stmt = (new Parser)->parse("<?php\n$code")->stmts->getItems()[0];
	assert($stmt instanceof ExpressionStatementNode);
	return $stmt;
}


test('hasComment() sees comments inside the node, not on its edges', function () {
	Assert::false(parseStatement("/* a */ f(1); // b\n")->hasComment());
	Assert::true(parseStatement("f(/* a */ 1);\n")->hasComment());
	Assert::true(parseStatement("f(\n\t1, // a\n);\n")->hasComment());
	Assert::true(parseStatement("f(1) /* a */;\n")->hasComment());
	Assert::false(parseStatement("f(1)\n\t;\n")->hasComment());
});


test('matches() compares token texts, not whitespace', function () {
	Assert::true(parseStatement("\$a[1] = 1;\n")->expr->matches(parseStatement("\$a [ 1 ]  =\n1;\n")->expr));
	Assert::false(parseStatement("\$a[1] = 1;\n")->expr->matches(parseStatement("\$a[2] = 1;\n")->expr));
});


test('isRepeatableRead()', function () {
	Assert::true(parseStatement("\$a->b[C::D];\n")->expr->isRepeatableRead());
	Assert::false(parseStatement("\$a->b();\n")->expr->isRepeatableRead());
	Assert::false(parseStatement("\$a[f()];\n")->expr->isRepeatableRead());
});
