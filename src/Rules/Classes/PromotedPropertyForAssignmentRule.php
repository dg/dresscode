<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ClassLikeNode, Expression, ParameterNode, Statement, TypeNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode};
use PhpSyntax\Nodes\Scalar\NullNode;
use function count;


/**
 * A property that the constructor does nothing with but assign a parameter of its own name to it is declared
 * by that parameter. The property must have no default value, no hooks, no attribute and no doc comment, because
 * the promoted parameter documents itself where it stands, and the types of the two must be written the same, so
 * that promotion narrows nothing. Neither the property nor the assignment may carry a comment, and a parameter
 * whose `null` default makes its type implicitly nullable is left alone, since the property would not be. The
 * assignment must be a statement of the constructor itself, not of a branch inside it, it must be the only place
 * the constructor reaches the property, and nothing in the constructor may write the parameter; a constructor
 * reaching a variable by a name it does not spell out keeps all its properties.
 *
 * Promotion assigns before the body runs, so a method the body calls before the assignment reads the value
 * instead of nothing. A typed property without a default answers such a read with an error, so no
 * working program can tell; an untyped one answers null, and the fix is risky there.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'], analyses: [NameResolver::class])]
final class PromotedPropertyForAssignmentRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.promotedProperties', Domain::adopted(), 'A constructor parameter only assigned to a property')];
	}


	public function getVisitedNodes(): array
	{
		return [ClassLikeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassLikeNode) {
			return;
		}

		$constructor = null;
		$properties = [];
		foreach ($node->members as $member) {
			if ($member instanceof MethodNode && $member->isConstructor()) {
				$constructor = $member;
			} elseif ($member instanceof PropertyNode && count($member->items) === 1) {
				$properties[$member->items->getItems()[0]->plainName] = $member;
			}
		}

		if (
			$constructor?->body === null
			|| NodeHelpers::findDynamicVariableAccesses($constructor, $context) !== []
		) {
			return;
		}

		foreach ($constructor->parameters->getItems() as $parameter) {
			$name = $parameter->promoted || $parameter->ellipsis !== null || $parameter->ampersand !== null
				? null
				: $parameter->variable->plainName;
			$property = $name === null ? null : $properties[$name] ?? null;
			$assignment = $property === null ? null : $this->findPromotable($constructor, $constructor->body, $parameter, $property, $name);
			if (
				$assignment === null
				|| !$context->report($property, "The property `\$$name` must be promoted to a constructor parameter.", risk: $property->type === null ? Risk::BehaviorChanges : null, because: $property->type === null ? 'promotion sets the untyped property before the body runs' : null)
			) {
				continue;
			}

			$this->promote($parameter, $property);
			$assignment->remove();
			$property->remove();
		}
	}


	/**
	 * The statement assigning the parameter to the property, where the two may take each other's place;
	 * null wherever anything else in the constructor would notice.
	 */
	private function findPromotable(
		MethodNode $constructor,
		Statement\BlockNode $body,
		ParameterNode $parameter,
		PropertyNode $property,
		string $name,
	): ?Statement\ExpressionStatementNode
	{
		$item = $property->items->getItems()[0];
		if (
			$property->modifiers->static
			|| $property->hooks !== null
			|| $item->default !== null
			|| $property->getDocComment() !== null
			|| count($property->attributes) > 0
			|| $property->hasInnerComment()
			|| $property->hasLeadingComment()
			|| $property->hasTrailingComment()
			|| ($parameter->default instanceof NullNode && $parameter->type?->isNullable() === false)
			|| $parameter->hasInnerComment()
			|| self::spell($property->type) !== self::spell($parameter->type)
		) {
			return null;
		}

		$found = null;
		foreach ($body->statements as $statement) {
			$expression = $statement instanceof Statement\ExpressionStatementNode ? $statement->expression : null;
			if (
				$expression instanceof Expression\AssignmentNode
				&& self::isPropertyOfThis($expression->target, $name)
				&& $expression->expression instanceof Expression\VariableNode
				&& $expression->expression->plainName === $name
				&& !$statement->hasInnerComment()
				&& !$statement->hasLeadingComment()
				&& !$statement->hasTrailingComment()
			) {
				$found = $statement;
				break;
			}
		}

		if ($found === null) {
			return null;
		}

		// the assignment must be the only fetch of the property, and nothing may write the parameter
		$fetches = $constructor->find(
			Expression\PropertyFetchNode::class,
			fn(Expression\PropertyFetchNode $fetch) => self::isPropertyOfThis($fetch, $name),
		);
		$writes = $constructor->find(
			Expression\VariableNode::class,
			fn(Expression\VariableNode $variable) => $variable->plainName === $name && $variable->isWritten(),
		);
		return count($fetches) === 1 && $writes === [] ? $found : null;
	}


	/** Writes the modifiers of the property in front of the parameter, `var` as `public`. */
	private function promote(ParameterNode $parameter, PropertyNode $property): void
	{
		foreach ($property->modifiers->getTokens() as $modifier) {
			$parameter->modifiers->append($modifier->is(Token::Var)
				? Token::fromText('public')
				: Token::fromText($modifier->text));
		}
	}


	/** Whether the expression is `$this->name` written with the plain operator and the plain name. */
	private static function isPropertyOfThis(Node $expression, string $name): bool
	{
		return $expression instanceof Expression\PropertyFetchNode
			&& $expression->isOfThis()
			&& !$expression->nullsafe
			&& $expression->plainName === $name;
	}


	/** The type as it is written, whitespace left out; two types spelled otherwise are two types here. */
	private static function spell(?TypeNode $type): string
	{
		return $type === null ? '' : (string) preg_replace('~\s+~', '', $type->text);
	}
}
