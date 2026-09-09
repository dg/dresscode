<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\FunctionLikeNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count;


/**
 * A foreach that only looks through the items and keeps a yes or a no, the item or its key, is one of the
 * functions PHP 8.4 gave arrays: `array_any()`, `array_all()`, `array_find()` and `array_find_key()`. Which
 * one the answer says: false turning into true asks whether any item passes, true turning into false whether
 * all of them do, and null turning into the item or its key looks for the first that passes.
 *
 * The loop is read in two shapes, the one that keeps the answer in a variable initialized right before it and
 * the one that returns it and returns the other answer after the loop. The body must be one `if` and nothing
 * else, the loop must take its items by value, and the variables of the loop must not be read after it, where
 * PHP leaves them behind but the closure of the call does not.
 */
#[RuleInfo(
	'dresscode/array-function-for-foreach',
	Stage::Structure,
	description: 'Replaces a foreach that only searches or tests its items with array_any(), array_all(), array_find() or array_find_key()',
	group: Group::Modernization,
	requires: ['php' => '>=8.4'],
)]
final class ArrayFunctionForForeachRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Statement\ExpressionStatementNode::class, Statement\ForeachNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$foreach, $outcome, $condition, $answer] = $node instanceof Statement\ForeachNode
			? $this->readReturning($node)
			: $this->readAssigning($node);
		if (
			$foreach === null
			|| $outcome === null
			|| $condition === null
			|| $answer === null
			|| !$this->isConvertible($foreach, $context)
		) {
			return;
		}

		$value = $foreach->value;
		$key = $foreach->key;
		assert($value instanceof Expression\VariableNode);
		[$function, $negate] = match (true) {
			self::isBoolean($answer, false) && self::isBoolean($outcome, true) => ['array_any', false],
			self::isBoolean($answer, true) && self::isBoolean($outcome, false) => ['array_all', true],
			!$answer instanceof NullNode => [null, false],
			self::isVariable($outcome, $value) => ['array_find', false],
			$key !== null && self::isVariable($outcome, $key) => ['array_find_key', false],
			default => [null, false],
		};
		if ($function === null || !$context->report($foreach, "The loop must be written with $function()")) {
			return;
		}

		$parameters = $key !== null && $condition->find(Expression\VariableNode::class, fn(Expression\VariableNode $v) => self::isVariable($v, $key)) !== []
			? $value->text . ', ' . $key->text
			: $value->text;
		$call = (new Parser)->parseExpression("$function(0, fn($parameters) => 0)");
		assert($call instanceof Expression\FunctionCallNode);
		[$array, $callback] = $call->arguments->items->getItems();
		assert($array instanceof ArgumentNode && $callback instanceof ArgumentNode);
		$array->value->replaceWith($foreach->expression->withoutEdgeTrivia());
		$arrow = $callback->value;
		assert($arrow instanceof Expression\ArrowFunctionNode);
		$arrow->expression->replaceWith($negate ? NodeHelpers::negate($condition) : $condition->withoutEdgeTrivia());

		if ($node instanceof Statement\ForeachNode) {
			$return = (new Parser)->parseFragment(Statement\ReturnNode::class, 'return 0;');
			assert($return->expression !== null);
			$return->expression->replaceWith($call);
			self::removeStatement(self::nextStatement($foreach));
			$foreach->replaceWith($return);
		} else {
			assert($node instanceof Statement\ExpressionStatementNode && $node->expression instanceof Expression\AssignmentNode);
			$node->expression->expression->replaceWith($call);
			self::removeStatement($foreach);
		}

		// the name is spelled once the call stands where the namespace can be read off it
		assert($call->name instanceof NameNode);
		$call->name->text = NodeHelpers::spellGlobalFunction($function, $call->name, $context);
	}


	/**
	 * The loop of the shape that keeps the answer in a variable: the statement is its initialization, the loop
	 * follows it, and the body of the loop assigns the other answer to that same variable and leaves.
	 * @return array{?Statement\ForeachNode, ?ExpressionNode, ?ExpressionNode, ?ExpressionNode}
	 */
	private function readAssigning(Node|Token $node): array
	{
		$none = [null, null, null, null];
		$assignment = $node instanceof Statement\ExpressionStatementNode ? $node->expression : null;
		$foreach = $node instanceof Node ? self::nextStatement($node) : null;
		if (
			!$assignment instanceof Expression\AssignmentNode
			|| !$assignment->target instanceof Expression\VariableNode
			|| !$foreach instanceof Statement\ForeachNode
			|| $node->hasComment()
		) {
			return $none;
		}

		$statements = self::readBody($foreach);
		$inner = $statements[0] ?? null;
		$outcome = $inner instanceof Statement\ExpressionStatementNode ? $inner->expression : null;
		return count($statements) === 2
			&& $statements[1] instanceof Statement\BreakNode
			&& $statements[1]->expression === null
			&& $outcome instanceof Expression\AssignmentNode
			&& $outcome->target instanceof Expression\VariableNode
			&& self::isVariable($outcome->target, $assignment->target)
			? [$foreach, $outcome->expression, self::readCondition($foreach), $assignment->expression]
			: $none;
	}


	/**
	 * The loop of the shape that returns the answer: the body returns one, and the statement after the loop
	 * returns the other.
	 * @return array{?Statement\ForeachNode, ?ExpressionNode, ?ExpressionNode, ?ExpressionNode}
	 */
	private function readReturning(Statement\ForeachNode $foreach): array
	{
		$none = [null, null, null, null];
		$statements = self::readBody($foreach);
		$after = self::nextStatement($foreach);
		return count($statements) === 1
			&& $statements[0] instanceof Statement\ReturnNode
			&& $statements[0]->expression !== null
			&& $after instanceof Statement\ReturnNode
			&& $after->expression !== null
			&& !$after->hasComment()
			? [$foreach, $statements[0]->expression, self::readCondition($foreach), $after->expression]
			: $none;
	}


	/**
	 * The statements the single `if` of the loop holds, empty where the loop does anything else.
	 * @return list<StatementNode>
	 */
	private static function readBody(Statement\ForeachNode $foreach): array
	{
		$if = $foreach->body instanceof Statement\BlockNode && count($foreach->body->statements) === 1
			? $foreach->body->statements->getItems()[0]
			: null;
		return $if instanceof Statement\IfNode
			&& count($if->elseifs) === 0
			&& $if->else === null
			&& $if->body instanceof Statement\BlockNode
			? $if->body->statements->getItems()
			: [];
	}


	private static function readCondition(Statement\ForeachNode $foreach): ?ExpressionNode
	{
		$if = $foreach->body instanceof Statement\BlockNode ? $foreach->body->statements->getItems()[0] ?? null : null;
		$condition = $if instanceof Statement\IfNode ? $if->condition : null;
		// a condition spread over lines becomes the body of an arrow function inside a call, which reads
		// worse than the loop it came from, so the loop keeps it
		return $condition !== null && $condition->getFirstToken()?->getLine() === $condition->getLastToken()?->getLine()
			? $condition
			: null;
	}


	/** Whether the loop takes its items by value, spells out its variables and leaves none of them behind. */
	private function isConvertible(Statement\ForeachNode $foreach, RuleContext $context): bool
	{
		$scope = $foreach->findAncestor(FunctionLikeNode::class) ?? $foreach->getFile();
		if (
			$foreach->ampersand !== null
			|| !$foreach->value instanceof Expression\VariableNode
			|| $foreach->value->plainName === null
			|| ($foreach->key !== null && (!$foreach->key instanceof Expression\VariableNode || $foreach->key->plainName === null))
			|| $foreach->hasComment()
			|| $scope === null
			|| NodeHelpers::findDynamicVariableAccesses($scope, $context) !== []
		) {
			return false;
		}

		// PHP leaves the variables of a loop behind, the closure of the call does not
		$names = [$foreach->value->plainName, $foreach->key?->plainName];
		$outside = $scope->find(
			Expression\VariableNode::class,
			fn(Expression\VariableNode $v) => in_array($v->plainName, $names, true) && !self::isInside($v, $foreach),
		);
		return $outside === [];
	}


	/** Whether the node lies in the subtree of the other one. */
	private static function isInside(Node $node, Node $ancestor): bool
	{
		for ($parent = $node->parent; $parent !== null; $parent = $parent->parent) {
			if ($parent === $ancestor) {
				return true;
			}
		}

		return false;
	}


	/** Removes a statement the call took over, the blank lines above it going with it, not to what follows. */
	private static function removeStatement(?Node $statement): void
	{
		$first = $statement?->getFirstToken();
		if ($first !== null && $first->startsLine()) {
			$first->setBlankLinesBefore(0);
		}

		$statement?->remove();
	}


	private static function nextStatement(Node $statement): ?Node
	{
		$list = $statement->parent;
		return $list instanceof NodeList ? $list->getItems()[$list->indexOf($statement) + 1] ?? null : null;
	}


	private static function isBoolean(ExpressionNode $expression, bool $value): bool
	{
		return $expression instanceof BooleanNode && $expression->value === $value;
	}


	private static function isVariable(ExpressionNode $expression, ExpressionNode $variable): bool
	{
		return $expression instanceof Expression\VariableNode
			&& $variable instanceof Expression\VariableNode
			&& $expression->plainName !== null
			&& $expression->plainName === $variable->plainName;
	}
}
