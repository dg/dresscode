<?php declare(strict_types=1);

use DressCode\Tools\Dumper;
use PhpSyntax\Nodes\Expression\TernaryNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\ModifiersNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('nodes with slots, lists and tokens with trivia', function () {
	$a = new Token(TokenKind::Variable, '$a');
	$a->leadingTrivia = [new Trivia(TriviaKind::OpenTag, "<?php\n"), new Trivia(TriviaKind::Whitespace, "\t")];
	$a->trailingTrivia = [new Trivia(TriviaKind::Whitespace, ' ', inInterpolation: true)];
	$ternary = new TernaryNode(
		new VariableNode(null, null, $a, null),
		new Token(ord('?'), '?'),
		null,
		new Token(ord(':'), ':'),
		new VariableNode(null, null, new Token(TokenKind::Variable, '$b'), null),
	);
	Assert::match(<<<'XX'
		TernaryNode
		  cond: VariableNode
		    name: Variable "$a"  <OpenTag"<?php\n" Whitespace"\t"  >Whitespace*" "
		  question: '?' "?"
		  colon: ':' ":"
		  else: VariableNode
		    name: Variable "$b"

		XX, Dumper::dump($ternary));

	$list = new SeparatedNodeList([new ModifiersNode([new Token(TokenKind::Public, 'public')]), new ModifiersNode], [new Token(ord(','), ',')]);
	Assert::match(<<<'XX'
		SeparatedNodeList
		  - ModifiersNode
		    - Public "public"
		  - ',' ","
		  - ModifiersNode

		XX, Dumper::dump($list));
	Assert::same("NodeList\n", Dumper::dump(new NodeList));
});


test('tree from the parser', function () {
	Assert::match(<<<'XX'
		FileNode
		  stmts: NodeList
		    - GenericNode
		      - VariableNode
		        name: Variable "$a"  <OpenTag"<?php "
		      - ';' ";"
		  eof: EndOfFile ""

		XX, Dumper::dump((new Parser)->parse('<?php $a;')));
});
