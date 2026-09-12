<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\FunctionLikeNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\ModifiersNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * A property the constructor sets and nothing else touches is `readonly`, which says so and makes PHP keep
 * it. The property must carry a type and no default value, which is what readonly takes, and every write of
 * it must stand in the constructor itself, a closure inside the constructor among the places that are not it.
 * A promoted parameter is read the same way, promotion being the write it needs.
 *
 * Who else may write the property the code shows by its visibility: a private one nothing outside the class
 * reaches, whatever the class is, and any other one only where the class is final. A public property of
 * a final class is a risky subject, the code that writes it from outside not being in the file; one of
 * a class that can be extended is left alone altogether, because a child redeclaring it would then be
 * a fatal error rather than a changed behaviour. A hooked property cannot be readonly at all.
 */
#[RuleInfo(
	'dresscode/readonly-for-unwritten-property',
	Stage::Structure,
	description: 'Marks a property written only in the constructor as readonly',
	group: Group::Modernization,
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
			if (self::isPropertyOfThis($fetch, $name) && self::isWritten($fetch)) {
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
			// what a class that is not final inherits to is not in the file, and a child redeclaring the
			// property as it is today would be a fatal error, which no consent to a risky fix covers
			|| (!$modifiers->isPrivate() && !$final)
			|| array_any($writes, fn(Node $write) => $write->findAncestor(FunctionLikeNode::class) !== $constructor)
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


	/** Whether the expression is `$this->name` written with the plain operator and the plain name. */
	private static function isPropertyOfThis(Expression\PropertyFetchNode $fetch, string $name): bool
	{
		return !$fetch->isNullsafe()
			&& $fetch->object instanceof Expression\VariableNode
			&& $fetch->object->plainName === 'this'
			&& $fetch->name instanceof IdentifierNode
			&& $fetch->name->text === $name;
	}


	/** Whether something writes the property: it is assigned, stepped, unset, or taken by reference. */
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
		) {
			[$node, $parent] = [$parent, $parent->parent];
		}

		return match (true) {
			$parent instanceof Expression\AssignmentNode,
			$parent instanceof Expression\AssignmentByReferenceNode,
			$parent instanceof Expression\CombinedAssignmentNode => $parent->findSlotOf($node) === 'target',
			$parent instanceof Expression\PrefixOpNode,
			$parent instanceof Expression\PostfixOpNode => true,
			$parent instanceof ArgumentNode => $parent->ampersand !== null,
			default => false,
		};
	}
}
