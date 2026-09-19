<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\RuleContext;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeGroupNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Parser;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * What the rules that write an attribute instead of an annotation share: the doc comment without the annotation,
 * or without itself where nothing else stood in it, and the attribute on a line of its own above the declaration.
 * @internal
 */
final class AnnotationToAttribute
{
	/**
	 * Writes the doc comment as the tree has it now, the annotations taken out of its children, and appends the
	 * attributes; the first one of a declaration that had none takes over what stood in front of the declaration.
	 * @param  NodeList<AttributeGroupNode>  $attributes  of the declaration
	 * @param  list<string>  $codes  the attributes as written between #[ and ]
	 * @param  PhpDoc  $phpDoc  the analysis that parsed the doc comment, which a mutation since may have made another of
	 */
	public static function apply(
		Node $declaration,
		NodeList $attributes,
		Trivia $docComment,
		PhpDocNode $tree,
		array $codes,
		PhpDoc $phpDoc,
		RuleContext $context,
	): void
	{
		// the doc comment is edited while it still stands where the node looks for it, in front of this token
		$first = $declaration->getFirstToken();
		if (PhpDoc::isEmpty($tree)) {
			$declaration->removeDocComment();
		} else {
			$declaration->replaceDocComment($phpDoc->print($tree, $docComment));
		}

		// a trivia stands in one place, so each is made anew
		$eol = fn() => new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol);
		$indentation = fn() => new Trivia(TriviaKind::Whitespace, $first?->getIndentation() ?? '');
		$hadAttributes = !$attributes->isEmpty();
		foreach ($codes as $index => $code) {
			$group = (new Parser)->parseFragment(AttributeGroupNode::class, "#[$code]");
			$attributes->append($group);
			if ($first === null) {
				continue;
			} elseif ($hadAttributes) {
				// the attribute before it ends its line, and so does this one, for what stands behind it
				$group->getFirstToken()?->setLeadingTrivia([$indentation()]);
				$group->getLastToken()?->setTrailingTrivia([$eol()]);
			} elseif ($index === 0) {
				// what stood in front of the declaration, the doc comment among it
				$group->getFirstToken()?->setLeadingTrivia($first->leadingTrivia);
				$first->setLeadingTrivia([$eol(), $indentation()]);
			} else {
				$group->getFirstToken()?->setLeadingTrivia([$eol(), $indentation()]);
			}
		}
	}


	/**
	 * Whether the declaration carries an attribute of the class already, whichever way its name is written.
	 * @param  NodeList<AttributeGroupNode>  $attributes
	 */
	public static function has(NodeList $attributes, string $class): bool
	{
		$shortName = preg_quote(substr($class, (int) strrpos('\\' . $class, '\\')), '~');
		return array_any(
			$attributes->getItems(),
			fn(Node $group) => preg_match("~(^|\\W)$shortName\\b~i", $group->text) === 1,
		);
	}
}
