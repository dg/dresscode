<?php declare(strict_types=1);

use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\Traverser;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


function label(Node|Token $node): string
{
	return $node instanceof Token ? "'$node->text'" : substr($node::class, strrpos($node::class, '\\') + 1);
}


test('pre-order with enter and leave', function () {
	$file = (new Parser)->parse('<?php $a;');
	$log = [];
	(new Traverser(
		function (Node|Token $node) use (&$log) { $log[] = '>' . label($node); },
		function (Node|Token $node) use (&$log) { $log[] = '<' . label($node); },
	))->traverse($file);
	Assert::same([
		'>FileNode', '>NodeList', '>ExpressionStatementNode', '>VariableNode', ">'\$a'", "<'\$a'", '<VariableNode',
		">';'", "<';'", '<ExpressionStatementNode', '<NodeList', ">''", "<''", '<FileNode',
	], $log);
});


test('a replaced node is neither descended into nor left', function () {
	$file = (new Parser)->parse('<?php $a; $b;');
	$log = [];
	(new Traverser(
		function (Node|Token $node) use (&$log) {
			$log[] = '>' . label($node);
			if ($node instanceof VariableNode && $node->name instanceof Token && $node->name->text === '$a') {
				$node->replaceWith((new Parser)->parseExpression('$x'));
			}
		},
		function (Node|Token $node) use (&$log) { $log[] = '<' . label($node); },
	))->traverse($file);
	Assert::same('<?php $x; $b;', (string) $file);
	Assert::same([
		'>FileNode', '>NodeList', '>ExpressionStatementNode', '>VariableNode',
		">';'", "<';'", '<ExpressionStatementNode',
		'>ExpressionStatementNode', '>VariableNode', ">'\$b'", "<'\$b'", '<VariableNode', ">';'", "<';'", '<ExpressionStatementNode',
		'<NodeList', ">''", "<''", '<FileNode',
	], $log);
});


test('siblings removed meanwhile are skipped, inserted ones wait for the next walk', function () {
	$file = (new Parser)->parse('<?php $a; $b; $c;');
	$visited = [];
	(new Traverser(function (Node|Token $node) use (&$visited, $file) {
		if ($node instanceof ExpressionStatementNode) {
			$visited[] = $name = $node->expr->getFirstToken()?->text;
			if ($name === '$a') {
				$file->stmts->getItems()[1]->remove();
				$file->stmts->append((new Parser)->parseStatement('$d;'));
			}
		}
	}))->traverse($file);
	Assert::same(['$a', '$c'], $visited);
	Assert::same('<?php $a;  $c;$d;', (string) $file);
});
