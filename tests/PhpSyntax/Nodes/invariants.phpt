<?php declare(strict_types=1);

/**
 * A fixed sequence of mutations over real code keeps the parent invariant and prints what the mutations imply.
 */

use PhpSyntax\CommentPolicy;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Parser\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


function assertParents(Node $node): void
{
	foreach ($node->getChildren() as $child) {
		Assert::same($node, $child->parent);
		if ($child instanceof Node) {
			assertParents($child);
		}
	}
}


function assertIndexed(FileNode $file): void
{
	$printed = (string) $file;
	foreach ($file->getIndex()->getTokens() as $token) {
		Assert::same($token->text, substr($printed, (int) $token->getOffset(), strlen($token->text)));
	}
}


test('mutations over a real file', function () {
	$code = (string) file_get_contents(__DIR__ . '/../Parser/corpus/wild/nette-utils.Arrays.php');
	$file = (new Parser)->parse($code);
	$parser = new Parser;
	$mutations = 0;

	foreach ($file->getDescendants(ExpressionStatementNode::class) as $i => $stmt) {
		if (!$stmt->getFile()) { // inside a subtree removed earlier
			continue;
		}

		match ($i % 4) {
			0 => $stmt->remove(),
			1 => $stmt->remove(CommentPolicy::Drop),
			2 => $stmt->replaceWith($parser->parseStatement('replaced();')),
			3 => $stmt->expr->replaceWith(clone $stmt->expr),
		};
		$mutations++;
	}

	Assert::true($mutations > 20);
	Assert::true($file->revision >= $mutations); // a compound mutation moves trivia in several steps
	assertParents($file);
	assertIndexed($file);
	Assert::true(str_contains((string) $file, 'replaced();'));
	Assert::false(str_contains((string) $file, "\n\n\n\n"));

	$copy = clone $file;
	assertParents($copy);
	Assert::same((string) $file, (string) $copy);
});
