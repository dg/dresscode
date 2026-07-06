<?php declare(strict_types=1);

namespace DressCode\Analyses;

use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\NodeVisitor\CloningVisitor;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Printer\Printer;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Doc comments as phpstan/phpdoc-parser trees: parse a DocComment trivia, edit the tree, print it back
 * preserving the original formatting.
 */
final class PhpDoc
{
	private readonly Lexer $lexer;
	private readonly PhpDocParser $parser;
	private readonly Printer $printer;

	/** @var \SplObjectStorage<Trivia, array{PhpDocNode, TokenIterator}>  the original tree and tokens of each parsed trivia */
	private \SplObjectStorage $parsed;


	public function __construct()
	{
		$config = new ParserConfig(usedAttributes: ['lines' => true, 'indexes' => true, 'comments' => true]);
		$this->lexer = new Lexer($config);
		$constExprParser = new ConstExprParser($config);
		$this->parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
		$this->printer = new Printer;
		$this->parsed = new \SplObjectStorage;
	}


	/**
	 * Returns a tree of the doc comment to read or edit; print() turns it back into a trivia.
	 */
	public function parse(Trivia $docComment): PhpDocNode
	{
		if ($docComment->kind !== TriviaKind::DocComment) {
			throw new \InvalidArgumentException('Not a doc comment.');
		}

		if (!isset($this->parsed[$docComment])) {
			$tokens = new TokenIterator($this->lexer->tokenize($docComment->text));
			$this->parsed[$docComment] = [$this->parser->parse($tokens), $tokens];
		}

		[$node] = $this->parsed[$docComment];
		$copy = (new NodeTraverser([new CloningVisitor]))->traverse([$node])[0];
		return $copy instanceof PhpDocNode ? $copy : throw new \LogicException('Cloning failed.');
	}


	/**
	 * Prints the edited tree as a new trivia, keeping the formatting of the original where the tree did not change.
	 */
	public function print(PhpDocNode $node, Trivia $original): Trivia
	{
		if (!isset($this->parsed[$original])) {
			throw new \InvalidArgumentException('The original doc comment was not parsed by this analysis.');
		}

		[$originalNode, $tokens] = $this->parsed[$original];
		return new Trivia(TriviaKind::DocComment, $this->printer->printFormatPreserving($node, $originalNode, $tokens), $original->inInterpolation);
	}
}
