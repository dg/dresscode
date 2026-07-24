<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Scalar\InterpolationNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * The `{$name}` interpolation instead of the deprecated `${name}`; anything but a bare name
 * inside the braces has a different meaning and stays.
 */
#[RuleInfo(
	'dresscode/complex-string-variable',
	Stage::Structure,
	description: 'Replaces the deprecated ${name} interpolation with {$name}',
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
			|| !$node->openBrace->is(TokenKind::DollarOpenCurlyBraces)
			|| !($var = $node->expression) instanceof VariableNode
			|| !($name = $var->name) instanceof Token
			|| !$name->is(TokenKind::StringVariableName)
			|| !$context->report($node, 'The deprecated ${name} interpolation must be written {$name}')
		) {
			return;
		}

		$node->openBrace = new Token(TokenKind::CurlyOpen, '{');
		$var->name = new Token(TokenKind::Variable, '$' . $name->text);
	}
}
