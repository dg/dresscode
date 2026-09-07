<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use DressCode\Tristate;
use PhpParser\Node as ParserNode;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\{InstantiationCallableNode, MethodCallableNode, StaticMethodCallableNode};
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Type\{Type, TypeCombinator};
use PhpSyntax\{Node, Printer, Token};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode};
use PhpSyntax\Nodes\{ExpressionNode, FileNode, IdentifierNode, NameNode};
use function count, strlen;


/**
 * The types of the code, from the PHPStan of the project: what an expression is, and which member a call or an
 * access reaches. The answers are those of the text of the pass at its first question; a node inserted after it
 * has none, and a rule asking for one asks with `?->`.
 * A rule asks questions; the answers in the words of PHPStan stay inside.
 */
final class Types implements PassAnalysis
{
	/** @var \SplObjectStorage<ExpressionNode, array{Expr, Scope}> */
	private \SplObjectStorage $expressions;

	/** @var ?\Closure(): array<ParserNode\Stmt>  the text parsed by PHPStan once asked, until the scopes are resolved from it */
	private ?\Closure $parse;

	/** @var array<string, list<ExpressionNode>>  "start:end" => the expressions the text stood at when the analysis was made, the innermost first */
	private array $spans = [];

	private readonly PhpStan $phpstan;


	/**
	 * The scopes wait for the first question that needs them, most of a file asking about classes alone; the nodes are
	 * found by where their text stood now, so that what the pass changes meanwhile moves nothing.
	 * @internal
	 */
	public function __construct(
		private readonly FileNode $file,
		private readonly string $path,
		PhpStan $phpstan,
	) {
		$this->expressions = new \SplObjectStorage;
		$code = Printer::print($file);
		$ast = null;
		$this->parse = function () use ($phpstan, $code, &$ast): array {
			return $ast ??= $phpstan->parse($code);
		};
		$this->phpstan = $phpstan->deriveFor($path, $code, $this->parse);
		$this->collectSpans($file);
	}


	/**
	 * Records where the text of the expressions under the node stands, as `FileNode::findNode()` does.
	 * @return ?array{int, int}  its offsets, the end exclusive; null for a node without tokens
	 */
	private function collectSpans(Node $node): ?array
	{
		$start = $end = null;
		foreach ($node->getChildren() as $child) {
			if ($child instanceof Token) {
				$offset = $child->currentOffset;
				$range = [$offset, $offset + strlen($child->text)];
			} else {
				$range = $this->collectSpans($child);
			}

			if ($range !== null) {
				$start ??= $range[0];
				$end = $range[1];
			}
		}

		if ($start === null || $end === null) {
			return null;
		} elseif ($node instanceof ExpressionNode) {
			$this->spans["$start:$end"][] = $node;
		}

		return [$start, $end];
	}


	private function resolveScopes(): void
	{
		if ($this->parse === null) {
			return;
		}

		$ast = ($this->parse)();
		$this->parse = null;
		$circular = []; // class => whether its ancestors run in a circle, which leaves the nodes inside it without types
		$this->phpstan->resolveScopes($this->path, $ast, function (ParserNode $node, Scope $scope) use (&$circular): void {
			$class = $scope->getClassReflection();
			if ($class !== null && ($circular[$class->getName()] ??= PhpStan::hasCircularAncestors($class))) {
				return;
			} elseif ($node instanceof Expr) {
				$expression = $this->findSpan($node);
				if ($expression !== null && !isset($this->expressions[$expression])) {
					$this->expressions[$expression] = [$node, $scope];
				}
			}
		});
	}


	/** The outermost expression the text of the node of PHPStan stood at. */
	private function findSpan(ParserNode $node): ?ExpressionNode
	{
		$nodes = $this->spans[$node->getStartFilePos() . ':' . ($node->getEndFilePos() + 1)] ?? [];
		return $nodes[count($nodes) - 1] ?? null;
	}


	/** @return ?array{Expr, Scope} */
	private function findExpression(ExpressionNode $node): ?array
	{
		$this->resolveScopes();
		return $this->expressions[$node] ?? null;
	}


	/** The type of the expression as PHPStan sees it; null for an expression the analysis was made without. */
	private function getType(ExpressionNode $expression): ?Type
	{
		$found = $this->findExpression($expression);
		if ($found === null) {
			return null;
		}

		[$node, $scope] = $found;
		return $scope->getType($node);
	}


	/**
	 * Whether the expression is of the type, written in the syntax of PHPDoc as PHPStan reads it (`int|string`,
	 * `list<int>`, `non-empty-string`), its classes fully qualified; maybe where it may or may not be, and for an
	 * expression the analysis was made without.
	 */
	public function isOfType(ExpressionNode $expression, string $type): Tristate
	{
		$actual = $this->getType($expression);
		if ($actual === null) {
			return Tristate::Maybe;
		}

		$answer = $this->phpstan->resolveType($type)->isSuperTypeOf($actual);
		return match (true) {
			$answer->yes() => Tristate::Yes,
			$answer->no() => Tristate::No,
			default => Tristate::Maybe,
		};
	}


	/**
	 * The classes the expression is an instance of, fully qualified; none for anything that is no object.
	 * @return list<string>
	 */
	public function findClasses(ExpressionNode $expression): array
	{
		return $this->getType($expression)?->getObjectClassNames() ?? [];
	}


	/**
	 * The member the node reaches: the constant of a class constant access, the method of a call, the property of
	 * an access, the constructor of an instantiation, decided by the class that declares it. Null for a node that
	 * reaches no known member, or whose name is an expression.
	 */
	public function findMember(ExpressionNode $node): ?Member
	{
		$receiver = $this->findReceiver($node);
		if ($receiver === null) {
			return null;
		}

		[$kind, $type, $name, $scope] = $receiver;
		$reflection = match ($kind) {
			MemberKind::Constant => $scope->getConstantReflection($type, $name),
			MemberKind::Method, MemberKind::StaticMethod, MemberKind::Constructor => $scope->getMethodReflection($type, $name),
			MemberKind::Property => $scope->getInstancePropertyReflection($type, $name),
			MemberKind::StaticProperty => $scope->getStaticPropertyReflection($type, $name),
		};
		if ($reflection === null) {
			return null;
		}

		// a property reflection does not say its name, which the declaration cannot spell differently anyway
		return new Member($kind, $reflection instanceof ExtendedPropertyReflection ? $name : $reflection->getName(), $reflection->getDeclaringClass()->getName());
	}


	/**
	 * The declared spelling of a class, interface, trait or enum the project, its packages or PHP declare, given its
	 * fully qualified name in any letter case without a leading backslash; null for a name nothing declares.
	 */
	public function findClassName(string $name): ?string
	{
		return $this->phpstan->findClassName($name);
	}


	/**
	 * What the syntax of a member access says and the types add: the kind, the type of the receiver, the name as
	 * written and the scope; null for a node that is no access, whose name is an expression or whose receiver is no object.
	 * @return ?array{MemberKind, Type, string, Scope}
	 */
	private function findReceiver(ExpressionNode $node): ?array
	{
		$found = $this->findExpression($node);
		if ($found === null) {
			return null;
		}

		[$parserNode, $scope] = $found;
		if (
			$parserNode instanceof MethodCallableNode
			|| $parserNode instanceof StaticMethodCallableNode
			|| $parserNode instanceof InstantiationCallableNode
		) {
			$parserNode = $parserNode->getOriginalNode(); // a first-class callable comes as a node of PHPStan's own
		}

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
			$node instanceof NewNode && $node->class instanceof NameNode && $parserNode instanceof Expr\New_ && $parserNode->class instanceof ParserNode\Name
				=> [MemberKind::Constructor, $scope->resolveTypeByName($parserNode->class), '__construct'],
			default => [null, null, null],
		};
		// a receiver that may be null is the class it may be: where it is null, the call fails before and after alike
		$type = $type === null ? null : TypeCombinator::removeNull($type);
		return $kind === null || $type === null || $name === null || $type->getObjectClassNames() === []
			? null // no class the member could be declared by: mixed, a scalar, an unknown variable
			: [$kind, $type, $name, $scope];
	}


	private static function classType(ParserNode\Name|Expr $class, Scope $scope): Type
	{
		return $class instanceof ParserNode\Name ? $scope->resolveTypeByName($class) : $scope->getType($class);
	}
}
