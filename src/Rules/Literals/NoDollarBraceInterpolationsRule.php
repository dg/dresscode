<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, VariableNode};
use PhpSyntax\Nodes\Scalar\InterpolationNode;


/**
 * The `{$name}` interpolation instead of the deprecated `${name}`, and `{$name[...]}` instead of `${name[...]}`,
 * which reads the element of the same array; `${expr}` names a variable by an expression and is written `{${expr}}`.
 */
#[RuleInfo(Stage::Structure)]
final class NoDollarBraceInterpolationsRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.php.dollarBraceInterpolation', Domain::state('forbidden'), '`${name}` is `{$name}`')];
	}


	public function getVisitedNodes(): array
	{
		return [InterpolationNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof InterpolationNode || !$node->openBrace->is(Token::DollarOpenCurlyBraces)) {
			return;
		}

		$var = $node->expression instanceof ArrayAccessNode ? $node->expression->expression : $node->expression;
		$name = $var instanceof VariableNode ? $var->name : null;
		if ($name instanceof Token && $name->is(Token::StringVariableName)) {
			if ($context->report($node, 'The deprecated `${name}` interpolation must be written `{$name}`.')) {
				$node->openBrace = new Token(Token::CurlyOpen, '{');
				$var->name = new Token(Token::Variable, '$' . $name->text);
			}

			return;
		}

		if ($context->report($node, 'The deprecated `${expr}` interpolation must be written `{${expr}}`.')) {
			$open = $node->openBrace;
			$close = $node->closeBrace;
			$first = $node->expression->getFirstToken();
			$last = $node->expression->getLastToken();
			[$leading, $trailing] = [$first->leadingTrivia, $last->trailingTrivia];
			$variable = (new Builder)->expression('${$e}', e: $node->expression);
			assert($variable instanceof VariableNode && $variable->name instanceof Node && $variable->openBrace && $variable->closeBrace);
			$variable->name->getFirstToken()->setLeadingTrivia($leading);
			$variable->name->getLastToken()->setTrailingTrivia($trailing);
			$variable->openBrace->setTrailingTrivia($open->trailingTrivia);
			$variable->closeBrace->setLeadingTrivia($close->leadingTrivia);
			$node->expression = $variable;
			$node->openBrace = new Token(Token::CurlyOpen, '{')->setLeadingTrivia($open->leadingTrivia);
			$node->closeBrace = Token::fromText('}')->setTrailingTrivia($close->trailingTrivia);
		}
	}
}
