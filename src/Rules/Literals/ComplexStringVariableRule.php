<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Scalar\InterpolationNode;


/**
 * The `{$name}` interpolation instead of the deprecated `${name}`; anything but a bare name
 * inside the braces has a different meaning and stays.
 */
#[RuleInfo(
	'dresscode/complexStringVariable',
	Stage::Structure,
	description: 'Replaces the deprecated `${name}` interpolation with `{$name}`',
	group: RuleGroup::Deprecations,
)]
final class ComplexStringVariableRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [InterpolationNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof InterpolationNode
			|| !$node->openBrace->is(Token::DollarOpenCurlyBraces)
			|| !($var = $node->expression) instanceof VariableNode
			|| !($name = $var->name) instanceof Token
			|| !$name->is(Token::StringVariableName)
			|| !$context->report($node, 'The deprecated `${name}` interpolation must be written `{$name}`')
		) {
			return;
		}

		$node->openBrace = new Token(Token::CurlyOpen, '{');
		$var->name = new Token(Token::Variable, '$' . $name->text);
	}
}
