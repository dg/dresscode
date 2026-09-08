<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\ClosureUseNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\StaticVariableNode;
use PhpSyntax\Nodes\TypeNode;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * A property that the constructor does nothing with but assign a parameter of its own name to it is declared
 * by that parameter. The property must have no default value, no hooks and no doc comment, because the promoted
 * parameter documents itself where it stands, and the types of the two must be written the same, so that
 * promotion narrows nothing. The assignment must be a statement of the constructor itself, not of a branch
 * inside it, and it must be the only place the constructor touches either the property or the parameter with
 * a write; a constructor reaching a variable by a name it does not spell out keeps all its properties.
 *
 * Promotion assigns before the body runs, so a body that reads the property before the assignment sees the
 * value instead of nothing. A typed property without a default answers such a read with an error, so no
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

		// what the assignment does must be all the constructor does with the property and with the parameter
		$fetches = $constructor->find(
			Expression\PropertyFetchNode::class,
			fn(Expression\PropertyFetchNode $fetch) => self::isPropertyOfThis($fetch, $name),
		);
		$writes = $constructor->find(
			Expression\VariableNode::class,
			fn(Expression\VariableNode $variable) => $variable->plainName === $name && self::isWritten($variable),
		);
		return count($fetches) === 1 && $writes === [] ? $found : null;
	}


	/** Moves the modifiers of the property in front of the parameter, which the leading trivia follows. */
	private function promote(ParameterNode $parameter, PropertyNode $property): void
	{
		$first = ($parameter->type ?? $parameter->variable)->getFirstToken();
		assert($first !== null); // a parameter always spells its variable
		$leading = $first->leadingTrivia;
		$first->setLeadingTrivia([]);
		foreach ($property->modifiers->getTokens() as $i => $modifier) {
			$token = new Token($modifier->kind, $modifier->text);
			$token->setLeadingTrivia($i === 0 ? $leading : []);
			$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			$parameter->modifiers->append($token);
		}
	}


	/** Whether the expression is `$this->name` written with the plain operator and the plain name. */
	private static function isPropertyOfThis(Node $expression, string $name): bool
	{
		return $expression instanceof Expression\PropertyFetchNode
			&& !$expression->isNullsafe()
			&& $expression->object instanceof Expression\VariableNode
			&& $expression->object->plainName === 'this'
			&& $expression->name instanceof IdentifierNode
			&& $expression->name->text === $name;
	}


	/** Whether something writes the variable: it is assigned, stepped, unset, bound, or taken by reference. */
	private static function isWritten(Expression\VariableNode $variable): bool
	{
		$node = $variable;
		$parent = $node->parent;
		while ( // destructuring writes every variable inside it
			$parent instanceof Expression\ParenthesizedNode
			|| $parent instanceof Expression\ArrayNode
			|| $parent instanceof Expression\ListNode
			|| $parent instanceof ArrayItemNode
		) {
			[$node, $parent] = [$parent, $parent->parent];
		}

		return match (true) {
			$parent instanceof Expression\AssignmentNode,
			$parent instanceof Expression\AssignmentByReferenceNode,
			$parent instanceof Expression\CombinedAssignmentNode => $parent->findSlotOf($node) === 'target',
			$parent instanceof Expression\PrefixOpNode,
			$parent instanceof Expression\PostfixOpNode => true,
			$parent instanceof ArgumentNode, $parent instanceof ClosureUseNode => $parent->ampersand !== null,
			$parent instanceof Statement\ForeachNode => $parent->findSlotOf($node) === 'key' || $parent->findSlotOf($node) === 'value',
			$parent instanceof Statement\UnsetNode,
			$parent instanceof Statement\GlobalNode,
			$parent instanceof StaticVariableNode,
			$parent instanceof CatchNode => true,
			default => false,
		};
	}


	/** The type as it is written, whitespace left out; two types spelled otherwise are two types here. */
	private static function spell(?TypeNode $type): string
	{
		return $type === null ? '' : (string) preg_replace('~\s+~', '', $type->text);
	}
}
