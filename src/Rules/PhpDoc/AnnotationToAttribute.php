<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\RuleContext;
use DressCode\Rules\CodeWriter;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PhpSyntax\{Node, Trivia};
use PhpSyntax\Nodes\{AttributeGroupNode, PlainNodeList};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, FunctionNode, InterfaceNode, TraitNode};


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
	 * @param  list<string>  $codes  the attributes as written between #[ and ]
	 * @param  PhpDoc  $phpDoc  the analysis that parsed the doc comment; after a mutation the file has another one
	 */
	public static function apply(
		ClassNode|InterfaceNode|TraitNode|EnumNode|FunctionNode|MethodNode|PropertyNode|ClassConstNode|EnumCaseNode $declaration,
		Trivia $docComment,
		PhpDocNode $tree,
		array $codes,
		PhpDoc $phpDoc,
		RuleContext $context,
	): void
	{
		if (PhpDoc::isEmpty($tree)) {
			$declaration->removeDocComment();
		} else {
			$declaration->replaceDocComment($phpDoc->print($tree, $docComment));
		}

		CodeWriter::addAttributes($declaration, $codes, $context);
	}


	/**
	 * Whether the declaration carries an attribute of the short name of the class already, whichever way it is written;
	 * one of another class of that short name counts too.
	 * @param  PlainNodeList<AttributeGroupNode>  $attributes
	 */
	public static function has(PlainNodeList $attributes, string $class): bool
	{
		$shortName = preg_quote(substr($class, (int) strrpos('\\' . $class, '\\')), '~');
		return array_any(
			$attributes->getItems(),
			fn(Node $group) => preg_match("~(^|\\W)$shortName\\b~i", $group->text) === 1,
		);
	}
}
