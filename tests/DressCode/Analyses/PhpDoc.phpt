<?php declare(strict_types=1);

use DressCode\Analyses\PhpDoc;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/** @return list<Trivia> */
function docComments(FileNode $file): array
{
	$result = [];
	foreach ($file->getIndex()->getTokens() as $token) {
		foreach ([...$token->leadingTrivia, ...$token->trailingTrivia] as $trivia) {
			if ($trivia->kind === TriviaKind::DocComment) {
				$result[] = $trivia;
			}
		}
	}

	return $result;
}


test('parse and read', function () {
	$file = (new Parser)->parse("<?php\n/**\n * Does things.\n * @param  int  \$a  the count\n * @return list<string>\n */\nfunction f(\$a) {}");
	$phpDoc = new PhpDoc;
	$node = $phpDoc->parse(docComments($file)[0]);
	Assert::same('Does things.', (string) $node->children[0]);
	Assert::count(1, $node->getParamTagValues());
	Assert::same('list<string>', (string) $node->getReturnTagValues()[0]->type);
	Assert::exception(fn() => $phpDoc->parse(new Trivia(TriviaKind::Comment, '// x')), InvalidArgumentException::class, 'Not a doc comment.');
});


test('edit and print preserving the format', function () {
	$file = (new Parser)->parse("<?php\nclass A {\n\t/** @var   int|null   the value */\n\tpublic \$a;\n}");
	$phpDoc = new PhpDoc;
	$original = docComments($file)[0];
	$node = $phpDoc->parse($original);
	$var = $node->getVarTagValues()[0];
	$var->type = new IdentifierTypeNode('?int');
	$printed = $phpDoc->print($node, $original);
	Assert::same('/** @var   ?int   the value */', $printed->text);
	Assert::same(TriviaKind::DocComment, $printed->kind);

	Assert::same($original->text, $phpDoc->print($phpDoc->parse($original), $original)->text);
	Assert::exception(fn() => $phpDoc->print($node, new Trivia(TriviaKind::DocComment, '/** */')), InvalidArgumentException::class);
});


test('round trip over the doc comments of the corpus', function () {
	$parser = new Parser;
	$count = 0;
	foreach (glob(__DIR__ . '/../../PhpSyntax/Parser/corpus/wild/*.php') as $path) {
		$file = $parser->parse((string) file_get_contents($path));
		$phpDoc = new PhpDoc;
		foreach (docComments($file) as $trivia) {
			Assert::same($trivia->text, $phpDoc->print($phpDoc->parse($trivia), $trivia)->text, $path);
			$count++;
		}
	}

	Assert::true($count > 200);
});
