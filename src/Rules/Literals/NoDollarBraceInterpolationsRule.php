<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, VariableNode};
use PhpSyntax\Nodes\Scalar\InterpolationNode;


/**
 * The `{$name}` interpolation instead of the deprecated `${name}`, and `{$name[...]}` instead of `${name[...]}`,
 * which reads the element of the same array; anything else inside the braces has a different meaning and stays.
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
		if (
			!$node instanceof InterpolationNode
			|| !$node->openBrace->is(Token::DollarOpenCurlyBraces)
			|| !($var = $node->expression instanceof ArrayAccessNode ? $node->expression->expression : $node->expression) instanceof VariableNode
			|| !($name = $var->name) instanceof Token
			|| !$name->is(Token::StringVariableName)
			|| !$context->report($node, 'The deprecated `${name}` interpolation must be written `{$name}`.')
		) {
			return;
		}

		$node->openBrace = new Token(Token::CurlyOpen, '{');
		$var->name = new Token(Token::Variable, '$' . $name->text);
	}
}
