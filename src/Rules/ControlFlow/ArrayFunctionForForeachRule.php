<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\{NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage, Tristate};
use DressCode\Rules\{CodeWriter, NodeHelpers};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\{ArgumentNode, ClassLikeNode, ConstItemNode, Expression, ExpressionNode, FunctionLikeNode, IdentifierNode, NameNode, ParameterNode, PlainNodeList, Statement, StatementNode, TypeNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, MethodNode, PropertyItemNode, PropertyNode};
use PhpSyntax\Nodes\Scalar\{BooleanNode, NullNode};
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NamedTypeNode, NullableTypeNode, UnionTypeNode};
use function count, in_array;


/**
 * A foreach that only looks through the items and keeps a yes or a no, the item or its key, is one of the
 * functions PHP 8.4 gave arrays: `array_any()`, `array_all()`, `array_find()` and `array_find_key()`. Which
 * one the answer says: false turning into true asks whether any item passes, true turning into false whether
 * all of them do, and null turning into the item or its key looks for the first that passes.
 *
 * The loop is read in two shapes, the one that keeps the answer in a variable initialized right before it and
 * the one that returns it and returns the other answer after the loop. The body must be one `if` and nothing
 * else, the loop must take its items by value, and the variables of the loop must appear nowhere else in their
 * scope: the loop writes them for the code around it, the closure of the call does not.
 *
 * The functions take an array and a foreach any iterable, so a loop over what is no array is left alone and the
 * fix is risky wherever the loop may go through something else. Only an array literal and what a declaration in
 * sight says are told from a Traversable.
 */
#[RuleInfo(
	'dresscode/arrayFunctionForForeach',
	Stage::Structure,
	description: 'Replaces a `foreach` that only searches or tests its items with `array_any()`, `array_all()`, `array_find()` or `array_find_key()`',
	group: RuleGroup::Modernization,
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
		$overArray = self::isArray($foreach);
		if (
			$function === null
			|| $overArray === Tristate::No
			|| !$context->report(
				$foreach,
				"The loop must be written with `$function()`",
				risk: $overArray === Tristate::Maybe ? Risk::TypeUnknown : null,
				because: $overArray === Tristate::Maybe ? 'the loop may go through an object, which `' . $function . '()` does not take' : null,
			)
		) {
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
		$call->name->text = CodeWriter::spellFunction($function, $call->name, $context);
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
			|| $node->hasInnerComment()
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
			&& !$after->hasInnerComment()
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
		return $condition !== null && !$condition->isMultiLine()
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
			|| $foreach->hasInnerComment()
			|| $scope === null
			|| NodeHelpers::findDynamicVariableAccesses($scope, $context) !== []
		) {
			return false;
		}

		$names = [$foreach->value->plainName, $foreach->key?->plainName];
		$outside = $scope->find(
			Expression\VariableNode::class,
			fn(Expression\VariableNode $v) => in_array($v->plainName, $names, true) && !self::isInside($v, $foreach),
		);
		return $outside === [];
	}


	/**
	 * Whether the loop goes through an array, as the declarations in sight tell: an array literal, a variadic
	 * parameter, and by its declared type a parameter nothing writes, and inside a method a property or a method of
	 * `$this` and a constant of the class. The type of a property or of what a method returns holds in a child too,
	 * which may not turn it into another; a constant is certain only through `self` or with a type, the untyped one
	 * a child may declare again.
	 */
	private static function isArray(Statement\ForeachNode $foreach): Tristate
	{
		$subject = $foreach->expression;
		$function = $foreach->findAncestor(FunctionLikeNode::class);
		$class = $function instanceof MethodNode ? $function->findAncestor(ClassLikeNode::class) : null;
		if ($subject instanceof Expression\ArrayNode) {
			return Tristate::Yes;

		} elseif ($subject instanceof Expression\VariableNode) {
			$parameter = array_find(
				$function?->parameters?->getItems() ?? [],
				fn(ParameterNode $parameter) => $parameter->variable->plainName === $subject->plainName,
			);
			return match (true) {
				$parameter === null,
				$function?->find(
					Expression\VariableNode::class,
					fn(Expression\VariableNode $variable) => $variable->plainName === $subject->plainName && $variable->isWritten(),
				) !== [] => Tristate::Maybe,
				$parameter->ellipsis !== null => Tristate::Yes,
				default => self::isArrayType($parameter->type),
			};

		} elseif ($class === null) {
			return Tristate::Maybe;

		} elseif (
			$subject instanceof Expression\PropertyFetchNode
			&& $subject->isOfThis()
			&& !$subject->isNullsafe()
			&& ($name = $subject->plainName) !== null
		) {
			foreach ($class->members as $member) {
				if (
					$member instanceof PropertyNode
					&& array_any($member->items->getItems(), fn(PropertyItemNode $item) => $item->plainName === $name)
				) {
					// a static property is not what `$this->` reads
					return $member->modifiers->isStatic() ? Tristate::Maybe : self::isArrayType($member->type);
				} elseif ($member instanceof MethodNode && $member->isConstructor()) {
					$promoted = array_find(
						$member->parameters->getItems(),
						fn(ParameterNode $parameter) => $parameter->isPromoted() && $parameter->variable->plainName === $name,
					);
					if ($promoted !== null) {
						return self::isArrayType($promoted->type);
					}
				}
			}

		} elseif (
			$subject instanceof Expression\MethodCallNode
			&& $subject->object instanceof Expression\VariableNode
			&& $subject->object->isThis()
			&& !$subject->isNullsafe()
			&& $subject->name instanceof IdentifierNode
			&& !$class instanceof Statement\TraitNode // the class using the trait may declare the method anew, in any type
		) {
			foreach ($class->members as $member) {
				if ($member instanceof MethodNode && $member->name->equals($subject->name->text)) {
					return self::isArrayType($member->returnType);
				}
			}

		} elseif (
			$subject instanceof Expression\ClassConstantFetchNode
			&& $subject->class instanceof NameNode
			&& in_array($own = strtolower($subject->class->text), ['self', 'static'], true)
			&& $subject->name instanceof IdentifierNode
		) {
			$name = $subject->name->text;
			foreach ($class->members as $member) {
				if (
					$member instanceof ClassConstNode
					&& ($item = array_find($member->items->getItems(), fn(ConstItemNode $item) => $item->name->text === $name)) !== null
				) {
					return match (true) {
						$member->type !== null => self::isArrayType($member->type),
						$own === 'self' && $item->value instanceof Expression\ArrayNode => Tristate::Yes,
						default => Tristate::Maybe,
					};
				}
			}
		}

		return Tristate::Maybe;
	}


	/** Whether a value of the declared type is an array; no type at all may be anything. */
	private static function isArrayType(?TypeNode $type): Tristate
	{
		if ($type instanceof NamedTypeNode) {
			return match (strtolower($type->name->text)) {
				'array' => Tristate::Yes,
				'iterable', 'callable', 'mixed' => Tristate::Maybe,
				default => Tristate::No,
			};
		} elseif ($type instanceof NullableTypeNode) {
			return self::isArrayType($type->type) === Tristate::No ? Tristate::No : Tristate::Maybe;
		} elseif ($type instanceof UnionTypeNode) {
			return array_all($type->types->getItems(), fn(TypeNode $member) => self::isArrayType($member) === Tristate::No)
				? Tristate::No
				: Tristate::Maybe;
		}

		return $type instanceof IntersectionTypeNode ? Tristate::No : Tristate::Maybe;
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
			$first->setBlankLinesBefore(0, "\n");
		}

		$statement?->remove();
	}


	private static function nextStatement(Node $statement): ?Node
	{
		$list = $statement->parent;
		return $list instanceof PlainNodeList ? $list->getItems()[$list->indexOf($statement) + 1] ?? null : null;
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
