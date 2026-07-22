<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;


/**
 * The combined operator for an assignment that repeats its target as the left operand:
 * `$a += $b`, not `$a = $a + $b`; only for targets free of side effects. An offset target stays,
 * because on a string offset the combined operator is a compile error. A property is a risky target:
 * `??=` writes nothing where the value is not null, so a readonly property, `__set` or a hook is not
 * reached, and the combined operator reads the property only after a right side that may have changed it.
 * A variable reads the same either way.
 */
#[RuleInfo(
	'dresscode/combined-assignment-operator',
	Stage::Structure,
	description: 'Uses += and friends where an assignment repeats its target',
)]
final class CombinedAssignmentOperatorRule extends NodeRule
{
	private const Operators = [
		'+' => '+=', '-' => '-=', '*' => '*=', '/' => '/=', '%' => '%=', '**' => '**=',
		'.' => '.=', '&' => '&=', '|' => '|=', '^' => '^=', '<<' => '<<=', '>>' => '>>=', '??' => '??=',
	];


	public function getVisitedTypes(): array
	{
		return [Expression\AssignmentNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof Expression\AssignmentNode
			|| !($var = $node->target) instanceof ExpressionNode
			|| $var instanceof Expression\ArrayAccessNode
			|| !($binary = $node->expression) instanceof Expression\BinaryOpNode
			|| ($combined = self::Operators[$binary->operator->text] ?? null) === null
			|| !$var->isRepeatableRead()
			|| !$var->matches($binary->left)
			|| ($right = $binary->right->getFirstToken()) === null
			|| $node->operator->getLine() !== $right->getLine()
			|| $node->operator->hasCommentUpTo($right)
		) {
			return;
		}

		$property = $var instanceof Expression\PropertyFetchNode || $var instanceof Expression\StaticPropertyFetchNode;
		$risky = $property && ($combined === '??=' || self::mayRunCode($binary->right));
		if (!$context->report($node, "The assignment must be written '$combined' instead of repeating its target", risky: $risky)) {
			return;
		}

		$replacement = (new Parser)->parseExpression('$x ' . $combined . ' 0');
		assert($replacement instanceof Expression\CombinedAssignmentNode);
		$replacement->operator->setLeadingTrivia($node->operator->leadingTrivia);
		$replacement->operator->setTrailingTrivia($node->operator->trailingTrivia);
		$replacement->target = clone $var;
		$replacement->expression = clone $binary->right;
		$replacement->setEdgeTrivia([], []);
		$node->replaceWith($replacement);
	}


	/** Whether evaluating the expression may run code, which could change the target before it is read. */
	private static function mayRunCode(ExpressionNode $expression): bool
	{
		foreach ([$expression, ...$expression->find(Node::class)] as $node) {
			if (
				$node instanceof Expression\FunctionCallNode
				|| $node instanceof Expression\MethodCallNode
				|| $node instanceof Expression\StaticMethodCallNode
				|| $node instanceof Expression\NewNode
				|| $node instanceof AnonymousClassNode
				|| $node instanceof Expression\AssignmentNode
				|| $node instanceof Expression\CombinedAssignmentNode
				|| $node instanceof Expression\AssignmentByReferenceNode
				|| $node instanceof Expression\PrefixOpNode
				|| $node instanceof Expression\PostfixOpNode
				|| $node instanceof Expression\IncludeNode
				|| $node instanceof Expression\EvalNode
				|| $node instanceof Expression\YieldNode
				|| $node instanceof Expression\YieldFromNode
				|| $node instanceof Expression\CloneNode
				|| $node instanceof Expression\ShellExecNode
			) {
				return true;
			}
		}

		return false;
	}
}
