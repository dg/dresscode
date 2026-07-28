<?php declare(strict_types=1);

namespace DressCode\Analyses;

use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\NodeVisitor\CloningVisitor;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPStan\PhpDocParser\Printer\Printer;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ClassLikeNode;
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


	/**
	 * Names declared by `@template` in the doc comment of the node and of its class; a native type cannot name them.
	 * @return list<string>
	 */
	public function findTemplates(Node $node): array
	{
		$names = [];
		foreach ([$node, $node->findAncestor(ClassLikeNode::class)] as $owner) {
			$docComment = $owner?->getDocComment();
			if ($docComment === null || $docComment->inInterpolation) {
				continue;
			}

			foreach ($this->parse($docComment)->children as $child) {
				if ($child instanceof PhpDocTagNode && $child->value instanceof TemplateTagValueNode) {
					$names[] = $child->value->name;
				}
			}
		}

		return $names;
	}


	/**
	 * Whether nothing but blank text is left in the tree; such a tree prints as an empty doc comment, so remove
	 * the doc comment instead of printing it.
	 */
	public static function isEmpty(PhpDocNode $node): bool
	{
		foreach ($node->children as $child) {
			if (!$child instanceof PhpDocTextNode || trim($child->text) !== '') {
				return false;
			}
		}

		return true;
	}
}
