<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count;


/**
 * `constant(Example::class . '::' . $name)` builds the name of a class constant out of pieces and asks for it
 * by string; PHP 8.3 fetches it directly, `Example::{$name}`. The class must be written as `X::class`, which
 * is the only form that says a class name is what it is, and the name of the constant may be any expression.
 */
#[RuleInfo(
	'dresscode/class-constant-fetch-for-constant-call',
	Stage::Structure,
	description: 'Fetches a class constant by its name instead of asking constant() for it',
	group: Group::Modernization,
	requires: ['php' => '>=8.3'],
)]
final class ClassConstantFetchForConstantCallRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode || $node->hasComment()) {
			return;
		}

		$argument = $node->arguments->items->getItems()[0] ?? null;
		if (
			count($node->arguments->items) !== 1
			|| !$argument instanceof ArgumentNode
			|| $argument->name || $argument->ampersand || $argument->ellipsis
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node, 'constant')
		) {
			return;
		}

		$concat = $argument->value;
		$prefix = $concat instanceof Expression\BinaryOpNode && $concat->operator->is('.') ? $concat->left : null;
		if (
			$prefix === null
			|| !$prefix instanceof Expression\BinaryOpNode
			|| !$prefix->operator->is('.')
			|| !self::namesClass($prefix->left)
			|| !$prefix->right instanceof StringNode
			|| $prefix->right->value !== '::'
			|| !$context->report($node, 'The class constant must be fetched by its name, not asked for by string')
		) {
			return;
		}

		assert($prefix->left instanceof Expression\ClassConstantFetchNode);
		$fetch = (new Parser)->parseExpression($prefix->left->class->text . '::{0}');
		assert($fetch instanceof Expression\ClassConstantFetchNode && $fetch->name instanceof ExpressionNode);
		$fetch->name->replaceWith($concat->right->withoutEdgeTrivia());
		$node->replaceWith($fetch);
	}


	/** Whether the expression is `X::class`, the only form that says a class name is what it holds. */
	private static function namesClass(ExpressionNode $expression): bool
	{
		return $expression instanceof Expression\ClassConstantFetchNode
			&& $expression->class instanceof NameNode
			&& $expression->name instanceof IdentifierNode
			&& strcasecmp($expression->name->text, 'class') === 0;
	}
}
