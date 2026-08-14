<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{ClassLikeNode, Expression, ParameterNode, Statement, TypeNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode};
use function count;


/**
 * A property that the constructor does nothing with but assign a parameter of its own name to it is declared
 * by that parameter. The property must have no default value, no hooks, no attribute and no doc comment, because
 * the promoted parameter documents itself where it stands, and the types of the two must be written the same, so
 * that promotion narrows nothing. The assignment must be a statement of the constructor itself, not of a branch
 * inside it, it must be the only place the constructor reaches the property, and nothing in the constructor may
 * write the parameter; a constructor reaching a variable by a name it does not spell out keeps all its properties.
 *
 * Promotion assigns before the body runs, so a method the body calls before the assignment reads the value
 * instead of nothing. A typed property without a default answers such a read with an error, so no
 * working program can tell; an untyped one answers null, and the fix is risky there.
 */
#[RuleInfo(
	'dresscode/promoted-property-for-assigned-parameter',
	Stage::Structure,
	description: 'Promotes a constructor parameter that is only assigned to a property',
	group: Group::Modernization,
	requires: ['php' => '>=8.0'],
)]
final class PromotedPropertyForAssignedParameterRule extends NodeRule
{
	public function getVisitedTypes(): array
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
			$name = $parameter->isPromoted() || $parameter->ellipsis || $parameter->ampersand
				? null
				: $parameter->variable->plainName;
			$property = $name === null ? null : $properties[$name] ?? null;
			$assignment = $property === null ? null : $this->findPromotable($constructor, $constructor->body, $parameter, $property, $name);
			if (
				$assignment === null
				|| !$context->report($property, 'The property must be promoted to a constructor parameter', risky: $property->type === null)
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
			$property->modifiers->isStatic()
			|| $property->hooks !== null
			|| $item->default !== null
			|| $property->getDocComment() !== null
			|| count($property->attributes) > 0
			|| $property->hasComment()
			|| $parameter->hasComment()
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
				&& !$statement->hasComment()
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
			fn(Expression\VariableNode $variable) => $variable->plainName === $name && NodeHelpers::isWritten($variable),
		);
		return count($fetches) === 1 && $writes === [] ? $found : null;
	}


	/** Writes the modifiers of the property in front of the parameter. */
	private function promote(ParameterNode $parameter, PropertyNode $property): void
	{
		foreach ($property->modifiers->getTokens() as $modifier) {
			$parameter->modifiers->append(new Token($modifier->kind, $modifier->text));
		}
	}


	/** Whether the expression is `$this->name` written with the plain operator and the plain name. */
	private static function isPropertyOfThis(Node $expression, string $name): bool
	{
		return $expression instanceof Expression\PropertyFetchNode
			&& $expression->isOfThis()
			&& !$expression->isNullsafe()
			&& $expression->plainName === $name;
	}


	/** The type as it is written, whitespace left out; two types spelled otherwise are two types here. */
	private static function spell(?TypeNode $type): string
	{
		return $type === null ? '' : (string) preg_replace('~\s+~', '', $type->text);
	}
}
