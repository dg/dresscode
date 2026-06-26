<?php declare(strict_types=1);

use PhpSyntax\Node;
use PhpSyntax\ParseException;
use PhpSyntax\Parser\GenericNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Printer;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function parse(string $code): GenericNode
{
	$node = (new Parser)->parse($code);
	Assert::same($code, Printer::print($node), 'round-trip');
	Assert::same($code, (string) $node);
	return $node;
}


/** @return list<string> */
function describe(Node|Token $node, int $depth = 0): array
{
	$label = match (true) {
		$node instanceof Token => "token '$node->text'",
		$node instanceof GenericNode => 'rule ' . $node->rule,
		default => 'node',
	};
	$lines = [str_repeat('  ', $depth) . $label];
	if ($node instanceof Node) {
		foreach ($node->getChildren() as $child) {
			$lines = [...$lines, ...describe($child, $depth + 1)];
		}
	}

	return $lines;
}


test('generic tree keeps every token and sets parents', function () {
	$root = parse("<?php\n\$a = f(1, 2); // c\n");
	Assert::same(0, $root->rule);
	Assert::null($root->parent);
	$eof = $root->children[count($root->children) - 1];
	Assert::type(Token::class, $eof);
	Assert::same(TokenKind::EndOfFile, $eof->kind);
	Assert::same($root, $eof->parent);

	$tokens = [];
	$walk = function (Node|Token $node) use (&$walk, &$tokens) {
		if ($node instanceof Token) {
			$tokens[] = $node->text;
		} else {
			foreach ($node->getChildren() as $child) {
				Assert::same($node, $child->parent);
				$walk($child);
			}
		}
	};
	$walk($root);
	Assert::same(['$a', '=', 'f', '(', '1', ',', '2', ')', ';', ''], $tokens);
});


test('single-child productions pass through, empty ones vanish', function () {
	// top_statement_list_ex (with the empty list dropped) > expr semi (variable chain passed through)
	Assert::match(
		"rule 0\n  rule %d%\n    rule %d%\n      token '\$a'\n      token ';'\n  token ''",
		implode("\n", describe(parse('<?php $a;'))),
	);
});


test('empty file, file without PHP, close tag as a statement terminator', function () {
	Assert::same(['rule 0', "  token ''"], describe(parse('')));
	Assert::match("rule 0\n  rule %d%\n    token 'x'\n  token ''", implode("\n", describe(parse('x'))));
	parse("<?php foo() ?>\n<b><?php bar(); ?>");
	parse('<?= $a ?>');
	parse('<?php if ($a): ?>x<?php endif ?>');
});


test('__halt_compiler data are part of the tree', function () {
	$root = parse('<?php __halt_compiler(); raw data');
	$data = $root->children[count($root->children) - 2];
	Assert::type(Token::class, $data);
	Assert::same(TokenKind::HaltCompilerData, $data->kind);
	Assert::same($root, $data->parent);
	parse("<?php __halt_compiler() ?>\nraw");
	parse('<?php __halt_compiler();');
});


test('newer syntax parses', function () {
	parse('<?php class A { public private(set) int $x { get => 1; } } $a |> f(...); (void) g(); echo __PROPERTY__;');
	parse('<?php function f((A&B)|null $x): static { return match(true) { default => new class {} }; }');
});


test('syntax errors', function () {
	$e = Assert::exception(fn() => parse("<?php\n\$a = ;"), ParseException::class, "Unexpected ';'");
	Assert::type(ParseException::class, $e);
	Assert::same([2, 11], [$e->originalLine, $e->originalOffset]);

	Assert::exception(fn() => parse('<?php $a'), ParseException::class, 'Unexpected end of file');
	Assert::exception(fn() => parse('<?php foo(1 2);'), ParseException::class, "Unexpected '2', expecting %a%");
	Assert::exception(fn() => parse('<?php class {}'), ParseException::class, "Unexpected '{', expecting T_STRING");
	Assert::exception(fn() => parse("<?php\nfoo(); ?>\n<?php }"), ParseException::class, "Unexpected '}'");
});
