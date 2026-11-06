<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Style};
use DressCode\Rules\NodeHelpers;
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocTagNode, VarTagValueNode};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Indentation, Node, Token, Trivia};
use PhpSyntax\Nodes\{ClassLikeNode, Expression, ParameterNode, Statement, TypeNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode};
use PhpSyntax\Nodes\Scalar\NullNode;
use function count;


/**
 * A property that the constructor does nothing with but assign a parameter of its own name to it is declared
 * by that parameter, its doc comment going above the parameter. The property must have no default value, no hooks
 * and no attribute, and the types of the two must be written the same, so that promotion narrows nothing, its `@var`
 * included, which above the parameter would narrow what the constructor takes beyond its type or its `@param`. Neither
 * the property nor the assignment may carry a comment other than that doc comment, and a parameter
 * whose `null` default makes its type implicitly nullable is left alone, since the property would not be. The
 * assignment must be a statement of the constructor itself, not of a branch inside it, it must be the only place
 * the constructor reaches the property, and nothing in the constructor may write the parameter; a constructor
 * reaching a variable by a name it does not spell out keeps all its properties.
 *
 * Promotion assigns before the body runs, so a method the body calls before the assignment reads the value
 * instead of nothing. A typed property without a default answers such a read with an error, so no
 * working program can tell; an untyped one answers null, and the fix is risky there.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.0'], modifiesComments: true, analyses: [PhpDoc::class, NameResolver::class])]
final class PromotedPropertyForAssignmentRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.syntax.promotedProperties',
			Domain::state('required'),
			'A constructor parameter only assigned to a property',
		)];
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
			$assignment = $property === null
				? null
				: self::findPromotable(
					$constructor,
					$constructor->body,
					$parameter,
					$property,
					$name,
					$context->getAnalysis(PhpDoc::class),
				);
			if (
				$assignment === null
				|| !$context->report(
					$property,
					"The property `\$$name` must be promoted to a constructor parameter.",
					risk: $property->type === null ? Risk::BehaviorChanges : null,
					because: $property->type === null ? 'promotion sets the untyped property before the body runs' : null,
				)
			) {
				continue;
			}

			self::promote($parameter, $property, $context->style);
			NodeHelpers::removeMergingGaps($assignment, $context->style->lineEnding);
			NodeHelpers::removeMergingGaps($property, $context->style->lineEnding);
		}
	}


	/**
	 * The statement assigning the parameter to the property, where the two may take each other's place;
	 * null wherever anything else in the constructor would notice.
	 */
	private static function findPromotable(
		MethodNode $constructor,
		Statement\BlockNode $body,
		ParameterNode $parameter,
		PropertyNode $property,
		string $name,
		PhpDoc $phpDoc,
	): ?Statement\ExpressionStatementNode
	{
		$item = $property->items->getItems()[0];
		$docComment = $property->getDocComment();
		if (
			$property->modifiers->static
			|| $property->hooks !== null
			|| $item->default !== null
			|| count($property->attributes) > 0
			|| $property->hasInnerComment()
			|| array_any($property->getLeadingComments(), fn(Trivia $comment) => $comment !== $docComment)
			|| $property->hasTrailingComment()
			|| ($docComment !== null && self::narrowsParameter($docComment, $property, $constructor, $name, $phpDoc))
			|| ($parameter->default instanceof NullNode && $parameter->type?->isNullable() === false)
			|| $parameter->hasInnerComment()
			|| self::normalizeType($property->type) !== self::normalizeType($parameter->type)
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


	/**
	 * Whether the `@var` of the property says a type the parameter does not take yet, by its native type or by the
	 * `@param` of the constructor, which above the promoted parameter would narrow what the constructor takes: a
	 * property of `?string` the constructor checks to be `'on'|'off'|null` before it assigns it. The types are compared
	 * as written.
	 */
	private static function narrowsParameter(
		Trivia $docComment,
		PropertyNode $property,
		MethodNode $constructor,
		string $name,
		PhpDoc $phpDoc,
	): bool
	{
		$normalize = fn(string $type) => (string) preg_replace('~\s+~', '', $type);
		$taken = [self::normalizeType($property->type)];
		$constructorDoc = $constructor->getDocComment();
		foreach ($constructorDoc === null ? [] : $phpDoc->parse($constructorDoc)->getParamTagValues() as $param) {
			if ($param->parameterName === "\$$name") {
				$taken[] = $normalize((string) $param->type);
			}
		}

		return array_any(
			$phpDoc->parse($docComment)->getTags(),
			fn(PhpDocTagNode $tag) => $tag->value instanceof VarTagValueNode
				&& !in_array($normalize((string) $tag->value->type), $taken, true),
		);
	}


	/**
	 * Writes the modifiers of the property in front of the parameter, `var` as `public`, and its doc comment above it on
	 * lines of its own, the parameter first moving to a line of its own, as a property declared in the class has it.
	 */
	private static function promote(ParameterNode $parameter, PropertyNode $property, Style $style): void
	{
		foreach ($property->modifiers->getTokens() as $modifier) {
			$parameter->modifiers->append($modifier->is(Token::Var)
				? Token::fromText('public')
				: Token::fromText($modifier->text));
		}

		$docComment = $property->getDocComment();
		if ($docComment === null) {
			return;
		}

		$property->removeDocComment();
		$first = $parameter->getFirstToken();
		if (!$first->startsLine()) {
			$first->ensureStartsLine($style->lineEnding);
			$first->setIndentation(Indentation::infer($first, $style->toPhpSyntax()));
		}

		$indentation = $first->getLineIndentation();
		$first->setLeadingTrivia([...$first->leadingTrivia, $docComment, Trivia::fromText($style->lineEnding)]);
		Indentation::set($first, $indentation, $indentation);
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
	private static function normalizeType(?TypeNode $type): string
	{
		return $type === null ? '' : (string) preg_replace('~\s+~', '', $type->text);
	}
}
