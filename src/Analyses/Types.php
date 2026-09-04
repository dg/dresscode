<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use PhpParser\Node as ParserNode;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\{ClassConstantReflection, ExtendedMethodReflection, ExtendedPropertyReflection};
use PHPStan\Type\Type;
use PhpSyntax\{Node, Printer};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\{ExpressionNode, FileNode, IdentifierNode};


/**
 * The types of the code, from the PHPStan of the project: what an expression is, which member a call or an
 * access reaches, and what the declaration of that member says about it. The answers are those of the text the
 * pass began with; a node inserted during the pass has none, and a rule asking for one asks with `?->`.
 * A rule asks questions, and getType() is the one answer in the words of PHPStan, for what the questions do
 * not cover; nothing else of the API names it.
 */
final class Types implements PassAnalysis
{
	/** @var \SplObjectStorage<ExpressionNode, array{Expr, Scope}> */
	private \SplObjectStorage $expressions;

	/** @var \WeakMap<Callee, ClassConstantReflection|ExtendedMethodReflection|ExtendedPropertyReflection> */
	private \WeakMap $reflections;


	public function __construct(FileNode $file, string $path, PhpStan $phpstan)
	{
		$this->expressions = new \SplObjectStorage;
		$this->reflections = new \WeakMap;
		$index = $file->getIndex();
		$phpstan->resolveScopes($path, $phpstan->parse(Printer::print($file)), function (ParserNode $node, Scope $scope) use ($index): void {
			if ($node instanceof Expr) {
				$expression = $index->findNode($node->getStartFilePos(), $node->getEndFilePos() + 1, ExpressionNode::class);
				if ($expression !== null && !isset($this->expressions[$expression])) {
					$this->expressions[$expression] = [$node, $scope];
				}
			}
		});
	}


	/** The type of the expression as PHPStan sees it; null for an expression the pass began without. */
	public function getType(ExpressionNode $expression): ?Type
	{
		if (!isset($this->expressions[$expression])) {
			return null;
		}

		[$node, $scope] = $this->expressions[$expression];
		return $scope->getType($node);
	}


	/**
	 * The member the node reaches: the constant of a class constant access, the method of a call, the property of
	 * an access, decided by the class that declares it. Null for a node that reaches no known member, or whose
	 * name is an expression.
	 */
	public function findCallee(Node $node): ?Callee
	{
		if (!$node instanceof ExpressionNode || !isset($this->expressions[$node])) {
			return null;
		}

		[$parserNode, $scope] = $this->expressions[$node];
		[$kind, $type, $name] = match (true) {
			$node instanceof ClassConstantFetchNode && $node->name instanceof IdentifierNode && $parserNode instanceof Expr\ClassConstFetch
				=> [MemberKind::Constant, self::classType($parserNode->class, $scope), $node->name->text],
			$node instanceof StaticMethodCallNode && $node->name instanceof IdentifierNode && $parserNode instanceof Expr\StaticCall
				=> [MemberKind::StaticMethod, self::classType($parserNode->class, $scope), $node->name->text],
			$node instanceof MethodCallNode && $node->name instanceof IdentifierNode && ($parserNode instanceof Expr\MethodCall || $parserNode instanceof Expr\NullsafeMethodCall)
				=> [MemberKind::Method, $scope->getType($parserNode->var), $node->name->text],
			$node instanceof StaticPropertyFetchNode && $node->plainName !== null && $parserNode instanceof Expr\StaticPropertyFetch
				=> [MemberKind::StaticProperty, self::classType($parserNode->class, $scope), $node->plainName],
			$node instanceof PropertyFetchNode && $node->name instanceof IdentifierNode && ($parserNode instanceof Expr\PropertyFetch || $parserNode instanceof Expr\NullsafePropertyFetch)
				=> [MemberKind::Property, $scope->getType($parserNode->var), $node->name->text],
			default => [null, null, null],
		};
		if ($kind === null || $type === null || $name === null || $type->getObjectClassNames() === []) {
			return null; // no class the member could be declared by: mixed, a scalar, an unknown variable
		}

		$reflection = match ($kind) {
			MemberKind::Constant => $scope->getConstantReflection($type, $name),
			MemberKind::Method, MemberKind::StaticMethod => $scope->getMethodReflection($type, $name),
			MemberKind::Property, MemberKind::StaticProperty => $scope->getPropertyReflection($type, $name),
		};
		if ($reflection === null) {
			return null;
		}

		// a property reflection does not say its name, which the declaration cannot spell differently anyway
		$callee = new Callee($kind, $reflection instanceof ExtendedPropertyReflection ? $name : $reflection->getName(), $reflection->getDeclaringClass()->getName());
		$this->reflections[$callee] = $reflection;
		return $callee;
	}


	/**
	 * What the declaration of the member says about its deprecation; null for a member that is not deprecated. The
	 * callee must come from this analysis, so one kept from an earlier pass is refused.
	 */
	public function getDeprecation(Callee $callee): ?Deprecation
	{
		$reflection = $this->reflections[$callee] ?? throw new \InvalidArgumentException('The callee is not one of this analysis.');
		return $reflection->isDeprecated()->yes()
			? Deprecation::fromDescription($reflection->getDeprecatedDescription() ?? '')
			: null;
	}


	private static function classType(ParserNode\Name|Expr $class, Scope $scope): Type
	{
		return $class instanceof ParserNode\Name ? $scope->resolveTypeByName($class) : $scope->getType($class);
	}
}
