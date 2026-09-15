<?php declare(strict_types=1);

namespace DressCode\Analyses;

use PhpParser\Node as ParserNode;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Type\Type;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Printer;


/**
 * The types of the code, from the PHPStan of the project: what an expression is, which member a call or an
 * access reaches, and what the declaration of that member says about it. The answers are those of the text the
 * pass began with; a node inserted during the pass has none, and a rule asking for one asks with `?->`.
 * The source is PHPStan today and the API does not say so: a rule asks questions, and getType() is the one
 * answer in the words of PHPStan, for what the questions do not cover.
 */
final class Types implements PassAnalysis
{
	/** @var \SplObjectStorage<ExpressionNode, array{Expr, Scope}> */
	private \SplObjectStorage $expressions;

	/** @var \SplObjectStorage<MethodNode, Scope> */
	private \SplObjectStorage $declarations;

	/** @var \WeakMap<Callee, ClassConstantReflection|ExtendedMethodReflection|ExtendedPropertyReflection> */
	private \WeakMap $reflections;


	public function __construct(FileNode $file, string $path, PhpStan $phpstan)
	{
		$this->expressions = new \SplObjectStorage;
		$this->declarations = new \SplObjectStorage;
		$this->reflections = new \WeakMap;
		$index = $file->getIndex();
		$phpstan->resolveScopes($path, $phpstan->parse(Printer::print($file)), function (ParserNode $node, Scope $scope) use ($index): void {
			if ($node instanceof Expr) {
				$expression = $index->findNode($node->getStartFilePos(), $node->getEndFilePos() + 1, ExpressionNode::class);
				if ($expression !== null && !isset($this->expressions[$expression])) {
					$this->expressions[$expression] = [$node, $scope];
				}
			} elseif ($node instanceof ParserNode\Stmt\ClassMethod) {
				$method = $index->findNode($node->getStartFilePos(), $node->getEndFilePos() + 1, MethodNode::class);
				if ($method !== null && !isset($this->declarations[$method])) {
					$this->declarations[$method] = $scope;
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
	 * The member of an ancestor the declaration overrides: the method of the same name declared by a parent
	 * class or by an interface, private to the ancestor or not a method of the pass excepted. Null where the
	 * declaration overrides nothing, and for a declaration the pass began without.
	 */
	public function findOverridden(Node $declaration): ?Callee
	{
		if (!$declaration instanceof MethodNode || !isset($this->declarations[$declaration])) {
			return null;
		}

		$scope = $this->declarations[$declaration];
		$class = $scope->getClassReflection();
		if ($class === null) {
			return null;
		}

		$name = $declaration->name->text;
		$parent = $class->getParentClass();
		foreach ([...($parent === null ? [] : [$parent]), ...$class->getInterfaces()] as $ancestor) {
			if (!$ancestor->hasMethod($name)) {
				continue;
			}

			$method = $ancestor->getMethod($name, $scope);
			if ($method->isPrivate()) {
				continue; // a private method of an ancestor is not overridden, it is hidden
			}

			$callee = new Callee(MemberKind::Method, $method->getName(), $method->getDeclaringClass()->getName());
			$this->reflections[$callee] = $method;
			return $callee;
		}

		return null;
	}


	/** What the declaration of the member says about its deprecation; null for a member that is not deprecated. */
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
