<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count;


/**
 * A comparison with null instead of `is_null()`: `$a === null`, `!is_null($a)` becomes `$a !== null`.
 * Parentheses are added where the operand or the surrounding expression would bind differently.
 */
#[RuleInfo(
	'dresscode/no-is-null',
	Stage::Structure,
	description: 'Replaces is_null() with a comparison with null',
	group: Group::OptimizedCalls,
)]
final class NoIsNullRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class, Expression\UnaryOpNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		// a negated call is rewritten where the negation stands, so that the rule replaces the node it
		// was given and the walk does not carry on inside what it took out of the tree
		$negated = self::isNegation($node);
		$call = $negated ? $node->expression : $node;
		if (!$call instanceof Expression\FunctionCallNode || (!$negated && self::isNegation($call->parent))) {
			return;
		}

		$target = $negated ? $node : $call;
		$args = $call->arguments->items->getItems();
		$arg = $args[0] ?? null;
		if (
			count($args) !== 1
			|| !$arg instanceof ArgumentNode
			|| $arg->name || $arg->ampersand || $arg->ellipsis
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, 'is_null')
			|| $target->hasComment()
			|| !$context->report(
				$call,
				'The is_null() call must be written as a comparison with null' . ($uncertainty = NodeHelpers::findUncertainty($call, $context)),
				risky: $uncertainty !== null,
			)
		) {
			return;
		}

		$null = (new Parser)->parseExpression('null');
		$target->replaceWithExpression(Expression\BinaryOpNode::of($arg->value->withoutEdgeTrivia(), $negated ? '!==' : '===', $null));
	}


	/** @phpstan-assert-if-true Expression\UnaryOpNode $node */
	private static function isNegation(Node|Token|null $node): bool
	{
		return $node instanceof Expression\UnaryOpNode && $node->operator->is('!');
	}
}
