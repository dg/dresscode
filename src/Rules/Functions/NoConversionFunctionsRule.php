<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\Scalar;
use PhpSyntax\Parser;
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
final class NoConversionFunctionsRule extends NodeRule
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
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode) {
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

		$args = $node->arguments->items->getItems();
		$arg = $args[0] ?? null;
		if (
			$cast === null
			|| count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name !== null
			|| $arg->ampersand !== null
			|| $arg->ellipsis !== null
			|| !$context->report($node, "The conversion function must be written as the '($cast)' cast")
		) {
			return;
		}

		$operand = clone $arg->value;
		$operand->setEdgeTrivia(leading: []);
		$operand->getLastToken()?->removeTrailingWhitespace();
		if ($this->bindsLooserThanCast($arg->value)) {
			$template = (new Parser)->parseExpression("($cast) (0)");
			assert($template instanceof Expression\CastNode && $template->expression instanceof Expression\ParenthesizedNode);
			$template->expression->expression = $operand;
		} else {
			$template = (new Parser)->parseExpression("($cast) 0");
			assert($template instanceof Expression\CastNode);
			$template->expression = $operand;
		}

		$node->replaceWith($template);
	}


	/** A cast binds tighter than most operators, so anything but a primary expression needs parentheses. */
	private function bindsLooserThanCast(Node $expr): bool
	{
		return !$expr instanceof Expression\VariableNode
			&& !$expr instanceof Expression\ArrayAccessNode
			&& !$expr instanceof Expression\PropertyFetchNode
			&& !$expr instanceof Expression\StaticPropertyFetchNode
			&& !$expr instanceof Expression\ClassConstantFetchNode
			&& !$expr instanceof Expression\ConstantFetchNode
			&& !$expr instanceof Expression\FunctionCallNode
			&& !$expr instanceof Expression\MethodCallNode
			&& !$expr instanceof Expression\StaticMethodCallNode
			&& !$expr instanceof Expression\ParenthesizedNode
			&& !$expr instanceof Scalar\IntegerNode
			&& !$expr instanceof Scalar\FloatNode
			&& !$expr instanceof Scalar\StringNode
			&& !$expr instanceof Scalar\BooleanNode
			&& !$expr instanceof Scalar\NullNode;
	}
}
