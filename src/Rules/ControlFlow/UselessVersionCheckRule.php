<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\ControlFlow;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocChildNode, PhpDocTagNode};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, ConstantFetchNode, FunctionCallNode, ParenthesizedNode, TernaryNode, UnaryOpNode};
use PhpSyntax\Nodes\{ExpressionNode, PlainNodeList, StatementNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Scalar\{IntegerNode, StringNode};
use PhpSyntax\Nodes\Statement\{BlockNode, ClassNode, IfNode};
use function in_array;


/**
 * A condition on the version of PHP that every version the code runs on decides the same way, which is there for
 * versions the project left behind: `if (PHP_VERSION_ID >= 80000) { a(); } else { b(); }` is `a();` for code on 8.0
 * or newer. `PHP_VERSION_ID`, `PHP_MAJOR_VERSION` and `version_compare(PHP_VERSION, '8.0', '>=')` compared with a
 * literal are read, alone, negated or joined by `&&` and `||`; the branch that always runs takes the place of the
 * `if` or of the ternary, and the others go. A dead branch holding a comment, an `if` whose `elseif` would take over,
 * the alternative syntax and a branch declaring a function or a class at the top of a file, which the `if` keeps from
 * being declared before the code runs, are only reported. An annotation `@requires PHP 8.0` of a test class or method,
 * which every version the code runs on meets, goes too.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [NameResolver::class, PhpDoc::class])]
final class UselessVersionCheckRule extends NodeRule
{
	/** the comparisons a version is checked by, with the operators of `version_compare()` they stand for */
	private const Operators = ['>=' => '>=', 'ge' => '>=', '>' => '>', 'gt' => '>', '<=' => '<=', 'le' => '<=', '<' => '<', 'lt' => '<'];

	/** the operator of a comparison read the other way round */
	private const Flipped = ['>=' => '<=', '>' => '<', '<=' => '>=', '<' => '>'];


	public static function getDecisions(): array
	{
		return [new Decision(
			'hygiene.uselessVersionCheck',
			Domain::state('forbidden'),
			'A check of the version of PHP every version the code runs on decides alike, `if (PHP_VERSION_ID >= 80000)` for code on 8.0',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [IfNode::class, TernaryNode::class, ClassNode::class, MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof ClassNode || $node instanceof MethodNode) {
			$this->checkRequirement($node, $context);
			return;
		} elseif (!$node instanceof IfNode && !$node instanceof TernaryNode) {
			return;
		}

		$value = $this->evaluate($node->condition, $context);
		if ($value === null) {
			return;
		}

		$message = "Useless version check, because PHP $context->phpVersion and newer, which the code runs on, always "
			. ($value ? 'pass' : 'fail') . ' it.';
		if ($node instanceof TernaryNode) {
			$branch = $value ? $node->then : $node->else;
			if ($branch !== null && !$node->hasInnerComment() && $context->report($node->condition, $message)) {
				$node->replaceWith($branch->withoutEdgeTrivia());
			}

			return;
		}

		$live = $value ? $node->body : ($node->elseifs->isEmpty() ? $node->else?->body : null);
		$fixable = match (true) {
			$node->body === null, // the alternative syntax
			!$node->parent instanceof PlainNodeList, // the body of another statement
			!$value && !$node->elseifs->isEmpty(),
			self::hasDeadComment($node, $live),
			$live instanceof BlockNode && NodeHelpers::hasHoistableDeclaration($node->parent, $live) => false,
			default => true,
		};
		if (!$context->report($node->condition, $message, fixable: $fixable) || !$fixable) {
			return;
		}

		$this->keepBranch($node, $live, $context);
	}


	/** Takes away an annotation `@requires PHP 8.0` of a test, which every version the code runs on meets. */
	private function checkRequirement(ClassNode|MethodNode $node, RuleContext $context): void
	{
		$docComment = $node->getDocComment();
		if ($docComment === null || stripos($docComment->text, '@requires') === false) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$met = fn(PhpDocChildNode $child) => $child instanceof PhpDocTagNode
			&& strtolower($child->name) === '@requires'
			&& preg_match('~^PHP\s+(?:>=\s*)?(\d+(?:\.\d+)*)\s*$~i', (string) $child->value, $m)
			&& version_compare($context->phpVersion, $m[1], '>=');
		if (
			array_any($tree->children, $met)
			&& $context->report(
				$node,
				"Useless version check, because PHP $context->phpVersion and newer, which the code runs on, always pass it.",
				trivia: $docComment,
			)
		) {
			PhpDoc::keepChildren($tree, array_values(array_filter($tree->children, fn(PhpDocChildNode $child) => !$met($child))));
			$phpDoc->writeBack($tree, $docComment, $node);
		}
	}


	/** Puts the branch that always runs in the place of the `if`, or takes the `if` away where none does. */
	private function keepBranch(IfNode $node, ?StatementNode $live, RuleContext $context): void
	{
		if ($live === null) {
			$node->remove(mergeBlankLines: true);
			return;
		}

		if ($live === $node->body) {
			$node->body = null;
		} elseif ($node->else !== null) {
			$node->else->body = null;
		}

		$node->replaceWith($live);
		if ($live instanceof BlockNode) {
			$statements = $live->statements->getItems();
			$live->unwrap();
			foreach ($statements as $statement) {
				Indentation::shift($statement, -1, $context->style->toPhpSyntax());
			}
		}
	}


	/** Whether a comment stands in what goes: the condition and the branches that never run. */
	private static function hasDeadComment(IfNode $node, ?StatementNode $live): bool
	{
		$kept = $live === null
			? []
			: array_fill_keys(array_map(spl_object_id(...), iterator_to_array($live->getTokens(), preserve_keys: false)), true);
		return array_any(
			iterator_to_array($node->getTokens(), preserve_keys: false),
			fn(Token $token) => !isset($kept[spl_object_id($token)]) && $token->hasComment(),
		);
	}


	/** What the condition is for every version the code runs on; null where they do not all decide it alike. */
	private function evaluate(ExpressionNode $condition, RuleContext $context): ?bool
	{
		return match (true) {
			$condition instanceof ParenthesizedNode => $this->evaluate($condition->expression, $context),
			$condition instanceof UnaryOpNode && $condition->operator->text === '!'
				=> ($inner = $this->evaluate($condition->expression, $context)) === null ? null : !$inner,
			$condition instanceof BinaryOpNode && in_array(strtolower($condition->operator->text), ['&&', 'and'], true)
				=> self::combine($this->evaluate($condition->left, $context), $this->evaluate($condition->right, $context), and: true),
			$condition instanceof BinaryOpNode && in_array(strtolower($condition->operator->text), ['||', 'or'], true)
				=> self::combine($this->evaluate($condition->left, $context), $this->evaluate($condition->right, $context), and: false),
			$condition instanceof BinaryOpNode => $this->evaluateComparison($condition, $context),
			$condition instanceof FunctionCallNode => $this->evaluateVersionCompare($condition, $context),
			default => null,
		};
	}


	/** `&&` and `||` of two answers, one of them deciding alone where it can. */
	private static function combine(?bool $left, ?bool $right, bool $and): ?bool
	{
		return match (true) {
			$left === !$and, $right === !$and => !$and,
			$left === null, $right === null => null,
			default => $and,
		};
	}


	/** `PHP_VERSION_ID >= 80000` or `PHP_MAJOR_VERSION < 8`, the literal on either side. */
	private function evaluateComparison(BinaryOpNode $comparison, RuleContext $context): ?bool
	{
		$operator = self::Operators[$comparison->operator->text] ?? null;
		[$constant, $literal] = [$comparison->left, $comparison->right];
		if ($constant instanceof IntegerNode) {
			[$constant, $literal] = [$literal, $constant];
			$operator = $operator === null ? null : self::Flipped[$operator];
		}

		$name = $constant instanceof ConstantFetchNode
			? $context->getAnalysis(NameResolver::class)->resolveConstant($constant->name)
			: null;
		[$major, $minor, $patch] = array_map(intval(...), explode('.', $context->phpVersion)) + [0, 0, 0];
		$version = match ($name) {
			'PHP_VERSION_ID' => $major * 10000 + $minor * 100 + $patch,
			'PHP_MAJOR_VERSION' => $major,
			default => null,
		};
		return $operator === null || $version === null || !$literal instanceof IntegerNode
			? null
			: self::decide($version <=> $literal->toValue(), $operator);
	}


	/** `version_compare(PHP_VERSION, '8.0', '>=')`, the version a literal. */
	private function evaluateVersionCompare(FunctionCallNode $call, RuleContext $context): ?bool
	{
		$arguments = $call->arguments;
		[$subject, $version, $operator] = [
			$arguments->findArgument('version1', 0)?->value,
			$arguments->findArgument('version2', 1)?->value,
			$arguments->findArgument('operator', 2)?->value,
		];
		$operator = $operator instanceof StringNode ? self::Operators[strtolower($operator->toValue())] ?? null : null;
		return $operator === null
			|| !$version instanceof StringNode
			|| !$subject instanceof ConstantFetchNode
			|| $context->getAnalysis(NameResolver::class)->resolveConstant($subject->name) !== 'PHP_VERSION'
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($call, 'version_compare')
			? null
			: self::decide(version_compare($context->phpVersion, $version->toValue()), $operator);
	}


	/**
	 * What the comparison is for the lowest version and every newer one, given how the lowest compares with the literal;
	 * null where a newer one may answer otherwise.
	 */
	private static function decide(int $lowest, string $operator): ?bool
	{
		return match ($operator) {
			'>=' => $lowest >= 0 ? true : null,
			'>' => $lowest > 0 ? true : null,
			'<' => $lowest >= 0 ? false : null,
			'<=' => $lowest > 0 ? false : null,
			default => null,
		};
	}
}
