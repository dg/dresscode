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
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use function in_array;


/**
 * The annotations agree with the code they describe: a `@param` naming a parameter the function does not have is
 * reported, a second `@return`, the function returning one value, and a `@param` or `@return` without content; the
 * other tags may stand alone, as `@internal` and `@deprecated` do.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpDoc::class])]
final class NoInvalidAnnotationsRule extends NodeRule
{
	private const MissingParameter = 'phpdoc.paramOfMissingParameter';
	private const DuplicateReturn = 'phpdoc.duplicateReturn';
	private const Empty = 'phpdoc.emptyAnnotation';


	public static function getDecisions(): array
	{
		return [
			new Decision(self::MissingParameter, Domain::state('forbidden'), 'A `@param` naming a parameter the function does not have'),
			new Decision(self::DuplicateReturn, Domain::state('forbidden'), 'A second `@return` of a function'),
			new Decision(self::Empty, Domain::state('forbidden'), 'A `@param` or `@return` without content'),
		];
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class, FunctionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof MethodNode && !$node instanceof FunctionNode)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$params = [];
		foreach ($node->parameters->getItems() as $param) {
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
			if (in_array($name, ['@param', '@return'], true) && PhpDoc::isEmptyTag($child)) {
				$context->report($node, "The `$name` annotation has no content.", decision: self::Empty, trivia: $docComment, fixable: false);
			} elseif (
				($value instanceof ParamTagValueNode || $value instanceof TypelessParamTagValueNode)
				&& !isset($params[$value->parameterName])
			) {
				$context->report($node, "The `@param` annotation names `{$value->parameterName}`, which is not a parameter.", decision: self::MissingParameter, trivia: $docComment, fixable: false);
			} elseif ($name === '@return' && ($counts[$name] = ($counts[$name] ?? 0) + 1) === 2) {
				$context->report($node, "The doc comment has more than one `$name` annotation.", decision: self::DuplicateReturn, trivia: $docComment, fixable: false);
			}
		}
	}
}
