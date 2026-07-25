<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\ArrayDimFetchNode;
use PhpSyntax\Nodes\Expression\CastNode;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\StaticCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Scalar\FloatNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use function count;


/**
 * A cast instead of the conversion functions: `(int) $x`, not `intval($x)`; a call with a base or
 * other extra arguments stays.
 */
#[RuleInfo(
	'dresscode/no-conversion-functions',
	Stage::Structure,
	description: 'Uses a cast instead of intval() and friends',
)]
final class NoConversionFunctionsRule extends Rule
{
	private const Casts = [
		'intval' => 'int',
		'floatval' => 'float',
		'doubleval' => 'float',
		'strval' => 'string',
		'boolval' => 'bool',
	];


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$cast = null;
		foreach (self::Casts as $function => $type) {
			if ($resolver->isGlobalFunctionCall($node, $function)) {
				$cast = $type;
				break;
			}
		}

		$args = $node->args->args->getItems();
		$arg = $args[0] ?? null;
		if (
			$cast === null
			|| count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name !== null
			|| $arg->byRef !== null
			|| $arg->ellipsis !== null
			|| !$context->report($node, "The conversion function must be written as the '($cast)' cast")
		) {
			return;
		}

		$operand = clone $arg->expr;
		$operand->getFirstToken()?->setLeadingTrivia([]);
		$operand->getLastToken()?->removeTrailingWhitespace();
		if ($this->bindsLooserThanCast($arg->expr)) {
			$template = (new Parser)->parseExpression("($cast) (0)");
			assert($template instanceof CastNode && $template->expr instanceof ParenthesizedNode);
			$template->expr->setExpr($operand);
		} else {
			$template = (new Parser)->parseExpression("($cast) 0");
			assert($template instanceof CastNode);
			$template->setExpr($operand);
		}

		$node->replaceWith($template);
	}


	/** A cast binds tighter than most operators, so anything but a primary expression needs parentheses. */
	private function bindsLooserThanCast(Node $expr): bool
	{
		return !$expr instanceof VariableNode
			&& !$expr instanceof ArrayDimFetchNode
			&& !$expr instanceof PropertyFetchNode
			&& !$expr instanceof StaticPropertyFetchNode
			&& !$expr instanceof ClassConstantFetchNode
			&& !$expr instanceof ConstantFetchNode
			&& !$expr instanceof FunctionCallNode
			&& !$expr instanceof MethodCallNode
			&& !$expr instanceof StaticCallNode
			&& !$expr instanceof ParenthesizedNode
			&& !$expr instanceof IntegerNode
			&& !$expr instanceof FloatNode
			&& !$expr instanceof StringNode;
	}
}
