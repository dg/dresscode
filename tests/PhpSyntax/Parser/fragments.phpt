<?php declare(strict_types=1);

use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Nodes\Statement\IfNode;
use PhpSyntax\Nodes\Type\UnionTypeNode;
use PhpSyntax\ParseException;
use PhpSyntax\Parser\Parser;
use PhpSyntax\TokenKind;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('parseExpression: detached, without positions, with empty trivia on the edges', function () {
	$expr = (new Parser)->parseExpression('$a + 1 // c');
	Assert::type(BinaryNode::class, $expr);
	Assert::null($expr->parent);
	Assert::same('$a + 1', (string) $expr);
	$first = $expr->getFirstToken();
	Assert::type(PhpSyntax\Token::class, $first);
	Assert::same([], $first->leadingTrivia);
	Assert::same([], $expr->getLastToken()?->trailingTrivia);
	Assert::null($first->originalLine);
	Assert::null($first->originalOffset);
	Assert::null($expr->getStartLine());
	Assert::same(' ', $expr->operator->trailingTrivia[0]->text);

	Assert::exception(fn() => (new Parser)->parseExpression('$a +'), ParseException::class, "Unexpected ';'");
	Assert::exception(fn() => (new Parser)->parseExpression('if (1) {}'), ParseException::class, 'Not an expression.');
});


test('parseStatement', function () {
	$stmt = (new Parser)->parseStatement("if (\$a) {\n\tb();\n}\n");
	Assert::type(IfNode::class, $stmt);
	Assert::same("if (\$a) {\n\tb();\n}", (string) $stmt);
	Assert::exception(fn() => (new Parser)->parseStatement('a(); b();'), ParseException::class, 'Exactly one statement expected, 2 given.');
	Assert::exception(fn() => (new Parser)->parseStatement(''), ParseException::class, 'Exactly one statement expected, 0 given.');
});


test('parseType and parseName', function () {
	$type = (new Parser)->parseType('int|(A&B)|null');
	Assert::type(UnionTypeNode::class, $type);
	Assert::same('int|(A&B)|null', (string) $type);
	Assert::exception(fn() => (new Parser)->parseType('1'), ParseException::class, "Unexpected '1'");

	$name = (new Parser)->parseName('\Foo\Bar');
	Assert::same(TokenKind::NameFullyQualified, $name->token->kind);
	Assert::same('static', (string) (new Parser)->parseName('static'));
	Assert::exception(fn() => (new Parser)->parseName('$a'), ParseException::class, 'Not a name.');
});
