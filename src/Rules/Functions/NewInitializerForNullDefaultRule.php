<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Types;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, ArgumentNode, ClassLikeNode, NameNode, ParameterNode, StatementNode, TypeNode};
use PhpSyntax\Nodes\Expression\{AssignmentNode, BinaryOpNode, CombinedAssignmentNode, NewNode, PropertyFetchNode, VariableNode};
use PhpSyntax\Nodes\Member\{MethodNode, TraitUseNode};
use PhpSyntax\Nodes\Scalar\NullNode;
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, ExpressionStatementNode, FunctionNode, TraitNode};
use PhpSyntax\Nodes\Type\{IntersectionTypeNode, NamedTypeNode, NullableTypeNode, UnionTypeNode};
use function count;


/**
 * A parameter defaulting to null only so that the body can put an object in its place is given the object as
 * its default, which PHP 8.1 allows: `?Clock $clock = null` with `$this->clock = $clock ?? new SystemClock;` among
 * the statements the body opens with is `Clock $clock = new SystemClock`, the statement keeping
 * `$this->clock = $clock;`, and `$clock ??= new SystemClock;` disappears. The arguments of `new` must be constant, as
 * a default asks, and its class a name of its own; a statement whose object is not ends the run of such statements,
 * since it may read a parameter, and so does a second one for the same parameter. Every fix is risky: the parameter no longer takes null, so a caller passing it gets a TypeError.
 * A method that may override another one is left alone, PHP forbidding it to narrow the type of a parameter, and so is
 * every method of a trait or of a class using one. Without the types, a method that overrides another one is not told
 * from one that does not, so every method of a class that extends or implements anything is left alone.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.1'], analyses: [Types::class])]
final class NewInitializerForNullDefaultRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.newInInitializer', Domain::adopted(), '`Clock $clock = new SystemClock` for a `null` default the body swaps for the object')];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionNode::class, MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ((!$node instanceof FunctionNode && !$node instanceof MethodNode) || $node->body === null) {
			return;
		}

		// the fallbacks the body opens with, up to one whose object may read a parameter, or a second one of
		// a parameter, which only the first one decides
		$seen = [];
		foreach ($node->body->statements->getItems() as $statement) {
			[$name, $new, $keep] = ($statement->hasInnerComment() ? null : self::readFallback($statement)) ?? [null, null, null];
			if ($name === null || $new === null || isset($seen[$name]) || !self::isConstantNew($new)) {
				return;
			}

			$seen[$name] = true;
			self::replaceFallback($node, $statement, $name, $new, $keep, $context);
		}
	}


	/** Writes the object the statement falls back to as the default of the parameter, where it may be one. */
	private static function replaceFallback(
		FunctionNode|MethodNode $node,
		StatementNode $statement,
		string $name,
		NewNode $new,
		?BinaryOpNode $keep,
		RuleContext $context,
	): void
	{
		$parameter = array_find(
			$node->parameters->getItems(),
			fn(ParameterNode $parameter) => $parameter->variable->plainName === $name,
		);
		$type = $parameter?->type;
		if (
			!$parameter instanceof ParameterNode
			|| $parameter->ampersand !== null
			|| $parameter->ellipsis !== null
			|| $parameter->promoted
			|| !$parameter->default instanceof NullNode
			|| $type === null
			|| ($written = self::writeWithoutNull($type)) === null
			|| ($node instanceof MethodNode && self::mayOverride($node, $context))
			|| !$context->report(
				$parameter,
				"The parameter `\$$name` must default to the object its body puts in place of null.",
				risk: Risk::BehaviorChanges,
				because: 'a caller passing null gets a TypeError',
			)
		) {
			return;
		}

		$builder = new Builder;
		$parameter->setType($builder->type($written));
		$parameter->default->replaceWith($new->withoutEdgeTrivia());
		if ($keep === null) {
			$statement->remove();
		} else {
			$keep->replaceWith($builder->expression('$' . $name));
		}
	}


	/**
	 * The name of the parameter the statement falls back from, the object it falls back to, and the coalescing to be
	 * replaced by the parameter alone, null where the whole statement goes.
	 * @return ?array{string, NewNode, ?BinaryOpNode}
	 */
	private static function readFallback(StatementNode $statement): ?array
	{
		$expression = $statement instanceof ExpressionStatementNode ? $statement->expression : null;
		if (
			$expression instanceof CombinedAssignmentNode
			&& $expression->operator->is('??=')
			&& $expression->target instanceof VariableNode
			&& $expression->expression instanceof NewNode
		) {
			return [(string) $expression->target->plainName, $expression->expression, null];
		}

		$coalesce = $expression instanceof AssignmentNode ? $expression->expression : null;
		if (
			!$coalesce instanceof BinaryOpNode
			|| !$coalesce->operator->is('??')
			|| !$coalesce->left instanceof VariableNode
			|| !$coalesce->right instanceof NewNode
			|| ($name = $coalesce->left->plainName) === null
		) {
			return null;
		}

		$target = $expression->target;
		return match (true) {
			$target instanceof VariableNode && $target->plainName === $name => [$name, $coalesce->right, null],
			$target instanceof PropertyFetchNode => [$name, $coalesce->right, $coalesce],
			default => null,
		};
	}


	/** The type without its null, null where nothing would be left or the type has none. */
	private static function writeWithoutNull(TypeNode $type): ?string
	{
		if ($type instanceof NullableTypeNode) {
			return $type->type->text;
		} elseif (!$type instanceof UnionTypeNode) {
			return null;
		}

		$rest = array_filter($type->types->getItems(), fn(Node $item) => !$item instanceof NamedTypeNode || strtolower($item->text) !== 'null');
		return match (true) {
			count($rest) !== count($type->types->getItems()) - 1, $rest === [] => null,
			count($rest) === 1 && ($only = reset($rest)) instanceof IntersectionTypeNode => $only->types->text,
			default => implode('|', array_map(fn(Node $item) => $item->text, $rest)),
		};
	}


	/** Whether the instantiation may stand as a default: a named class and constant arguments. */
	private static function isConstantNew(NewNode $new): bool
	{
		return $new->class instanceof NameNode
			&& !ForwardingClosure::isScopeRelative($new->class->text)
			&& array_all(
				$new->arguments?->items->getItems() ?? [],
				fn(Node $argument) => $argument instanceof ArgumentNode
					&& $argument->ellipsis === null
					&& $argument->value->isConstantExpression(),
			);
	}


	/** Whether the method may override another one, whose parameter PHP forbids it to narrow. */
	private static function mayOverride(MethodNode $method, RuleContext $context): bool
	{
		$class = $method->findAncestor(ClassLikeNode::class);
		if (
			$class instanceof TraitNode // the class using it may inherit a declaration of the method
			|| array_any($class?->members->getItems() ?? [], fn(Node $member) => $member instanceof TraitUseNode) // the trait may declare it abstract
		) {
			return true;
		}

		$inherits = match (true) {
			$class instanceof ClassNode, $class instanceof AnonymousClassNode => $class->extends !== null || $class->implements !== null,
			$class instanceof EnumNode => $class->implements !== null,
			default => true,
		};
		$types = $context->findAnalysis(Types::class);
		return $inherits
			&& ($types?->findDeclaringClass($method) === null || $types->findOverridden($method) !== null);
	}
}
