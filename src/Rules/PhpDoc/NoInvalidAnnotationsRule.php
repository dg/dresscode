<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{ParamTagValueNode, PhpDocTagNode, TypelessParamTagValueNode};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\FunctionNode;
use function in_array;


/**
 * The annotations agree with the code they describe: a `@param` naming a parameter the function does not have is
 * reported, a second `@return`, the function returning one value, a second `@var` of a property, which has one type,
 * and a `@param`, `@return`, `@var` or `@see` without content; the other tags may stand alone, as `@internal` and
 * `@deprecated` do.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpDoc::class])]
final class NoInvalidAnnotationsRule extends NodeRule
{
	private const MissingParameter = 'phpdoc.annotation.paramOfMissingParameter';
	private const DuplicateReturn = 'phpdoc.annotation.duplicateReturn';
	private const DuplicateVar = 'phpdoc.annotation.duplicateVar';
	private const Empty = 'phpdoc.annotation.empty';


	public static function getDecisions(): array
	{
		return [
			new Decision(self::MissingParameter, Domain::state('forbidden'), 'A `@param` naming a parameter the function does not have'),
			new Decision(self::DuplicateReturn, Domain::state('forbidden'), 'A second `@return` of a function'),
			new Decision(self::DuplicateVar, Domain::state('forbidden'), 'A second `@var` of a property'),
			new Decision(self::Empty, Domain::state('forbidden'), 'A `@param`, `@return`, `@var` or `@see` without content'),
		];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class, FunctionNode::class, PropertyNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof MethodNode && !$node instanceof FunctionNode && !$node instanceof PropertyNode)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$property = $node instanceof PropertyNode;
		$params = [];
		foreach ($property ? [] : $node->parameters->getItems() as $param) {
			if ($param->variable->name instanceof Token) {
				$params[$param->variable->name->text] = true;
			}
		}

		$counts = [];
		foreach ($context->getAnalysis(PhpDoc::class)->parse($docComment)->children as $child) {
			if (!$child instanceof PhpDocTagNode) {
				continue;
			}

			$value = $child->value;
			$name = strtolower($child->name);
			if (in_array($name, ['@param', '@return', '@var', '@see'], true) && PhpDoc::isEmptyTag($child)) {
				$context->report($node, "The `$name` annotation has no content.", decision: self::Empty, trivia: $docComment, fixable: false);
			} elseif (
				!$property
				&& ($value instanceof ParamTagValueNode || $value instanceof TypelessParamTagValueNode)
				&& !isset($params[$value->parameterName])
			) {
				$context->report($node, "The `@param` annotation names `{$value->parameterName}`, which is not a parameter.", decision: self::MissingParameter, trivia: $docComment, fixable: false);
			} elseif ($name === ($property ? '@var' : '@return') && ($counts[$name] = ($counts[$name] ?? 0) + 1) === 2) {
				$context->report($node, "The doc comment has more than one `$name` annotation.", decision: $property ? self::DuplicateVar : self::DuplicateReturn, trivia: $docComment, fixable: false);
			}
		}
	}
}
