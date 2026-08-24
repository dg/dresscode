<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, TokenKind, Trivia, TriviaKind};
use PhpSyntax\Nodes\{AnonymousClassNode, ArgumentNode, ArrayItemNode, Expression, FunctionLikeNode, IdentifierNode, ModifiersNode, ParameterNode, SeparatedNodeList, Statement};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\ClassNode;
use function count;


/**
 * A property the constructor sets and nothing else touches is `readonly`, which says so and makes PHP keep
 * it. The property must carry a type and no default value, which is what readonly takes, and every write of
 * it must go through $this and stand in the constructor itself: a closure inside the constructor is not it,
 * and a write to a clone is what readonly refuses. A promoted parameter is read the same way, promotion being
 * the write it needs.
 *
 * Who else may write the property the code shows by its visibility: a private one nothing outside the class
 * reaches, whatever the class is, and any other one only where the class is final. A property that is not
 * private is a risky subject in a final class, the code that writes it from outside not being in the file; one of
 * a class that can be extended is left alone altogether, because a child redeclaring it would then be
 * a fatal error rather than a changed behaviour. A hooked property cannot be readonly at all.
 */
#[RuleInfo(
	'dresscode/readonly-for-unwritten-property',
	Stage::Structure,
	description: 'Marks a property written only in the constructor as readonly',
	requires: ['php' => '>=8.1'],
)]
final class ReadonlyForUnwrittenPropertyRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof AnonymousClassNode)
			|| $node->modifiers->isReadonly()
		) {
			return;
		}

		$constructor = null;
		foreach ($node->members as $member) {
			if ($member instanceof MethodNode && $member->isConstructor()) {
				$constructor = $member;
			}
		}

		if ($constructor === null) {
			return;
		}

		$final = $node instanceof AnonymousClassNode || $node->modifiers->isFinal();
		foreach ($node->members as $member) {
			if ($member instanceof PropertyNode && count($member->items) === 1) {
				$item = $member->items->getItems()[0];
				$this->consider($member, $member->modifiers, $item->plainName, $item->default !== null, $node, $constructor, $final, $context);
			}
		}

		foreach ($constructor->parameters->getItems() as $parameter) {
			if ($parameter->isPromoted() && $parameter->variable->plainName !== null) {
				$this->consider($parameter, $parameter->modifiers, $parameter->variable->plainName, false, $node, $constructor, $final, $context);
			}
		}
	}


	/** Marks the member readonly where nothing but the constructor writes it. */
	private function consider(
		PropertyNode|ParameterNode $member,
		ModifiersNode $modifiers,
		string $name,
		bool $hasDefault,
		ClassNode|AnonymousClassNode $class,
		MethodNode $constructor,
		bool $final,
		RuleContext $context,
	): void
	{
		$writes = [];
		foreach ($class->find(Expression\PropertyFetchNode::class) as $fetch) {
			if ($fetch->name instanceof IdentifierNode && $fetch->name->text === $name && self::isWritten($fetch)) {
				$writes[] = $fetch;
			}
		}

		$promoted = $member instanceof ParameterNode;
		if (
			$modifiers->isReadonly()
			|| $modifiers->isStatic()
			|| $hasDefault
			|| ($member instanceof PropertyNode && ($member->type === null || $member->hooks !== null))
			|| ($member instanceof ParameterNode && ($member->type === null || $member->hooks !== null))
			|| ($writes === [] && !$promoted)
			|| (!$modifiers->isPrivate() && !$final)
			|| array_any($writes, fn(Expression\PropertyFetchNode $write) => !$write->isOfThis() || $write->isNullsafe() || $write->findAncestor(FunctionLikeNode::class) !== $constructor)
		) {
			return;
		}

		$risky = !$modifiers->isPrivate();
		if (!$context->report($member, 'The property nothing but the constructor writes must be readonly', risky: $risky)) {
			return;
		}

		$token = new Token(TokenKind::Readonly, 'readonly');
		$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		$modifiers->append($token);
	}


	/** Whether something writes the property: it is assigned, stepped, unset, iterated into, or taken by reference. */
	private static function isWritten(Expression\PropertyFetchNode $fetch): bool
	{
		$node = $fetch;
		$parent = $node->parent;
		while (
			($parent instanceof Expression\ArrayAccessNode && $parent->expression === $node)
			|| ($parent instanceof Expression\PropertyFetchNode && $parent->object === $node)
			|| $parent instanceof Expression\ListNode
			|| $parent instanceof Expression\ArrayNode
			|| $parent instanceof ArrayItemNode
			|| $parent instanceof SeparatedNodeList
		) {
			[$node, $parent] = [$parent, $parent->parent];
		}

		return match (true) {
			$parent instanceof Expression\AssignmentNode,
			$parent instanceof Expression\AssignmentByReferenceNode,
			$parent instanceof Expression\CombinedAssignmentNode => $parent->findSlotOf($node) === 'target',
			$parent instanceof Expression\PrefixOpNode,
			$parent instanceof Expression\PostfixOpNode,
			$parent instanceof Statement\UnsetNode => true,
			$parent instanceof Statement\ForeachNode => $parent->findSlotOf($node) === 'key' || $parent->findSlotOf($node) === 'value',
			$parent instanceof ArgumentNode => $parent->ampersand !== null,
			default => false,
		};
	}
}
