<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * An inline `@var` annotation of a variable assigned by the statement below it (also in foreach and while)
 * becomes an `assert()` of the type after the statement, or at the start of the loop body. Types that assert()
 * cannot express (generics, shapes, pseudo-types, `@template` names) keep their annotation.
 */
#[RuleInfo(
	'dresscode/explicit-assertion',
	Stage::Structure,
	description: 'Replaces an inline @var annotation with an assert() of the type',
	modifiesComments: true,
)]
final class ExplicitAssertionRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [Statement\ExpressionStatementNode::class, Statement\ForeachNode::class, Statement\WhileNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof Statement\ExpressionStatementNode && !$node instanceof Statement\ForeachNode && !$node instanceof Statement\WhileNode)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
			|| ($place = self::findPlace($node, $context->getStyle()->indent)) === null
		) {
			return;
		}

		[$list, $index, $indentation, $names] = $place;
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$function = $node->findAncestor(Statement\FunctionNode::class) ?? $node->findAncestor(MethodNode::class);
		$templates = $function === null ? [] : $phpDoc->findTemplates($function);
		$assertions = $kept = [];
		foreach ($tree->children as $child) {
			$condition = $child instanceof PhpDocTagNode && $child->value instanceof VarTagValueNode && in_array($child->value->variableName, $names, strict: true)
				? self::buildCondition($child->value->variableName, $child->value->type, $templates)
				: null;
			if ($condition === null) {
				$kept[] = $child;
			} else {
				$assertions[] = $condition;
			}
		}

		if (
			$assertions === []
			|| !$context->report($node, 'An inline @var annotation must be an assert() of the type', trivia: $docComment)
		) {
			return;
		}

		$tree->children = $kept;
		if (PhpDoc::isEmpty($tree)) {
			$node->removeDocComment();
		} else {
			$node->replaceDocComment($phpDoc->print($tree, $docComment));
		}

		$eol = $context->getStyle()->eol;
		foreach ($assertions as $i => $condition) {
			$assert = (new Parser)->parseStatement("assert($condition);");
			$assert->getFirstToken()?->setLeadingTrivia($indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)]);
			$assert->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $eol)]);
			$list->insert($index + $i, $assert);
			$assert->getFirstToken()?->ensureLeadingNewline($eol);
		}
	}


	/**
	 * Where the assertions go and which variables the statement assigns: after an assignment statement,
	 * or at the start of the body of a foreach or of a while with an assignment in its condition.
	 * @return ?array{NodeList<StatementNode>, int, string, list<string>}
	 */
	private static function findPlace(
		Statement\ExpressionStatementNode|Statement\ForeachNode|Statement\WhileNode $node,
		string $indent,
	): ?array
	{
		$indentation = $node->getFirstToken()?->getLineIndentation() ?? '';
		if ($node instanceof Statement\ExpressionStatementNode) {
			$list = $node->parent;
			return $list instanceof NodeList && $node->expr instanceof Expression\AssignNode && $node->expr->operator->is('=')
				? [$list, $list->indexOf($node) + 1, $indentation, self::collectVariables($node->expr->var)]
				: null;
		}

		$names = [];
		if ($node instanceof Statement\ForeachNode) {
			$names = [...($node->keyVar ? self::collectVariables($node->keyVar) : []), ...self::collectVariables($node->valueVar)];
		} else {
			foreach ([$node->cond, ...$node->cond->getDescendants(Expression\AssignNode::class)] as $assign) {
				if ($assign instanceof Expression\AssignNode && $assign->operator->is('=')) {
					$names = [...$names, ...self::collectVariables($assign->var)];
				}
			}
		}

		return $node->body instanceof Statement\BlockNode && $names !== []
			? [$node->body->stmts, 0, $indentation . $indent, $names]
			: null;
	}


	/**
	 * Names of the plain variables assigned by the target of an assignment: the variable itself, or those
	 * directly in a destructuring list.
	 * @return list<string>
	 */
	private static function collectVariables(ExpressionNode $target): array
	{
		if ($target instanceof Expression\VariableNode) {
			return $target->name instanceof Token && $target->dollar === null ? [$target->name->text] : [];
		} elseif ($target instanceof Expression\ArrayNode || $target instanceof Expression\ListNode) {
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
	 * The condition asserting the type, null when assert() cannot express it.
	 * @param  list<string>  $templates
	 */
	private static function buildCondition(string $variable, Type\TypeNode $type, array $templates): ?string
	{
		if ($type instanceof Type\NullableTypeNode) {
			$inner = self::buildCondition($variable, $type->type, $templates);
			return $inner === null ? null : "$inner || $variable === null";
		} elseif ($type instanceof Type\UnionTypeNode || $type instanceof Type\IntersectionTypeNode) {
			$parts = [];
			foreach ($type->types as $member) {
				$part = self::buildCondition($variable, $member, $templates);
				if ($part === null) {
					return null;
				}

				$parts[] = $part;
			}

			return implode($type instanceof Type\UnionTypeNode ? ' || ' : ' && ', $parts);
		} elseif (!$type instanceof Type\IdentifierTypeNode) {
			return null;
		}

		$name = $type->name;
		return match (strtolower($name)) {
			'int', 'integer' => "is_int($variable)",
			'float', 'double' => "is_float($variable)",
			'string' => "is_string($variable)",
			'bool', 'boolean' => "is_bool($variable)",
			'array' => "is_array($variable)",
			'callable' => "is_callable($variable)",
			'iterable' => "is_iterable($variable)",
			'object' => "is_object($variable)",
			'resource' => "is_resource($variable)",
			'numeric' => "is_numeric($variable)",
			'scalar' => "is_scalar($variable)",
			'true', 'false', 'null' => "$variable === " . strtolower($name),
			'self', 'static' => "$variable instanceof " . strtolower($name),
			default => preg_match('~^\\\?[A-Z][\w\\\]*$~', $name) && !in_array($name, $templates, strict: true)
				? "$variable instanceof $name"
				: null,
		};
	}
}
