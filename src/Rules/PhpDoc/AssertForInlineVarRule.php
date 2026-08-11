<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocTagNode, VarTagValueNode};
use PHPStan\PhpDocParser\Ast\Type;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, SymbolKind, Token, Trivia};
use PhpSyntax\Nodes\{DestructuringNode, Expression, ExpressionNode, PlainNodeList, Statement, StatementNode};
use PhpSyntax\Nodes\Member\MethodNode;
use function in_array;


/**
 * An inline `@var` annotation of a variable assigned by the statement below it (also in `foreach` and `while`)
 * becomes an `assert()` of the type after the statement, or at the start of the loop body. Types that `assert()`
 * cannot express (generics, shapes, pseudo-types, `@template` names, type aliases) keep their annotation, and so
 * does a class whose name starts in lower case, which is not told from a pseudo-type.
 *
 * Every fix is risky: the `assert()` runs where assertions are on and fails where the annotation was wrong, which
 * the annotation never did.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [PhpDoc::class, NameResolver::class])]
final class AssertForInlineVarRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('types.inlineVarAnnotation', Domain::state('forbidden'), 'An inline `@var` says what `assert($x instanceof Foo)` checks')];
	}


	public function getVisitedNodes(): array
	{
		return [Statement\ExpressionStatementNode::class, Statement\ForeachNode::class, Statement\WhileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof Statement\ExpressionStatementNode && !$node instanceof Statement\ForeachNode && !$node instanceof Statement\WhileNode)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
			|| ($place = self::findPlace($node, $context->style->indent)) === null
		) {
			return;
		}

		[$list, $index, $indentation, $names] = $place;
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$function = $node->findAncestor(Statement\FunctionNode::class) ?? $node->findAncestor(MethodNode::class);
		$localTypes = $phpDoc->findLocalTypes($function ?? $node);
		$resolver = $context->getAnalysis(NameResolver::class);
		$spell = fn(string $function): string => $resolver->shortenName($function, SymbolKind::Function, $node);
		$assertions = $kept = [];
		foreach ($tree->children as $child) {
			$condition = $child instanceof PhpDocTagNode && $child->value instanceof VarTagValueNode && in_array($child->value->variableName, $names, true)
				? self::buildCondition($child->value->variableName, $child->value->type, $localTypes, $spell)
				: null;
			if ($condition === null) {
				$kept[] = $child;
			} else {
				$assertions[] = $condition;
			}
		}

		if (
			$assertions === []
			|| !$context->report($node, 'The inline `@var` annotation must be written as an `assert()` of the type.', trivia: $docComment, risk: Risk::BehaviorChanges, because: 'a wrong annotation then stops the program where it only misled an analyzer')
		) {
			return;
		}

		$tree->children = $kept;
		$phpDoc->writeBack($tree, $docComment, $node);

		$eol = $context->style->lineEnding;
		foreach ($assertions as $i => $condition) {
			$assert = (new Builder)->statement("assert($condition);");
			$assert->setEdgeTrivia($indentation === '' ? [] : [new Trivia(Trivia::Whitespace, $indentation)], [Trivia::fromText($eol)]);
			$list->insert($index + $i, $assert);
			$assert->getFirstToken()->ensureStartsLine($eol);
		}
	}


	/**
	 * Where the assertions go and which variables the statement assigns: after an assignment statement,
	 * or at the start of the body of a foreach or of a while with an assignment in its condition.
	 * @return ?array{PlainNodeList<StatementNode>, int, string, list<string>}
	 */
	private static function findPlace(
		Statement\ExpressionStatementNode|Statement\ForeachNode|Statement\WhileNode $node,
		string $indent,
	): ?array
	{
		$indentation = $node->getFirstToken()->getLineIndentation();
		if ($node instanceof Statement\ExpressionStatementNode) {
			$list = $node->parent;
			return $list instanceof PlainNodeList && $node->expression instanceof Expression\AssignmentNode
				? [$list, $list->indexOf($node) + 1, $indentation, self::collectVariables($node->expression->target)]
				: null;
		}

		$names = [];
		if ($node instanceof Statement\ForeachNode) {
			$names = [...($node->key ? self::collectVariables($node->key) : []), ...self::collectVariables($node->value)];
		} else {
			foreach ([$node->condition, ...$node->condition->find(Expression\AssignmentNode::class)] as $assign) {
				if ($assign instanceof Expression\AssignmentNode) {
					$names = [...$names, ...self::collectVariables($assign->target)];
				}
			}
		}

		return $node->body instanceof Statement\BlockNode && $names !== []
			? [$node->body->statements, 0, $indentation . $indent, $names]
			: null;
	}


	/**
	 * Names of the plain variables assigned by the target of an assignment: the variable itself, or those
	 * directly in a destructuring list.
	 * @return list<string>
	 */
	private static function collectVariables(ExpressionNode|DestructuringNode $target): array
	{
		if ($target instanceof Expression\VariableNode) {
			return $target->name instanceof Token && $target->dollar === null ? [$target->name->text] : [];

		} elseif ($target instanceof Expression\ArrayNode || $target instanceof DestructuringNode) {
			$names = [];
			foreach ($target->items->getItems() as $item) {
				if (isset($item->value) && $item->value instanceof Expression\VariableNode) {
					$names = [...$names, ...self::collectVariables($item->value)];
				}
			}

			return $names;
		}

		return [];
	}


	/**
	 * The condition asserting the type, null when `assert()` cannot express it. `assert()` itself cannot be declared in a
	 * namespace, so it is written bare; a function of the condition is spelled by `$spell`.
	 * @param  list<string>  $localTypes  names of templates and type aliases, which are no classes
	 * @param  \Closure(string): string  $spell
	 */
	private static function buildCondition(
		string $variable,
		Type\TypeNode $type,
		array $localTypes,
		\Closure $spell,
	): ?string
	{
		if ($type instanceof Type\NullableTypeNode) {
			$inner = self::buildCondition($variable, $type->type, $localTypes, $spell);
			return $inner === null ? null : "$inner || $variable === null";

		} elseif ($type instanceof Type\UnionTypeNode || $type instanceof Type\IntersectionTypeNode) {
			$parts = [];
			foreach ($type->types as $member) {
				$part = self::buildCondition($variable, $member, $localTypes, $spell);
				if ($part === null) {
					return null;
				}

				$parts[] = $type instanceof Type\IntersectionTypeNode && str_contains($part, ' || ') ? "($part)" : $part;
			}

			return implode($type instanceof Type\UnionTypeNode ? ' || ' : ' && ', $parts);

		} elseif (!$type instanceof Type\IdentifierTypeNode) {
			return null;
		}

		$name = $type->name;
		$function = match (strtolower($name)) {
			'int', 'integer' => 'is_int',
			'string' => 'is_string',
			'bool', 'boolean' => 'is_bool',
			'array' => 'is_array',
			'callable' => 'is_callable',
			'iterable' => 'is_iterable',
			'object' => 'is_object',
			'resource' => 'is_resource',
			'numeric' => 'is_numeric',
			'scalar' => 'is_scalar',
			default => null,
		};
		return match (true) {
			$function !== null => $spell($function) . "($variable)",
			in_array(strtolower($name), ['float', 'double'], true) => $spell('is_float') . "($variable) || " . $spell('is_int') . "($variable)",
			in_array(strtolower($name), ['true', 'false', 'null'], true) => "$variable === " . strtolower($name),
			in_array(strtolower($name), ['self', 'static'], true) => "$variable instanceof " . strtolower($name),
			preg_match('~^\\\?[A-Z][\w\\\]*$~', $name) && !in_array($name, $localTypes, true) => "$variable instanceof $name",
			default => null,
		};
	}
}
