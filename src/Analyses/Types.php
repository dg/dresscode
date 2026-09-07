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
use PHPStan\Type\Type;
use PhpSyntax\{Node, Printer, Token};
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\{ExpressionNode, FileNode};
use function count, strlen;


/**
 * The types of the code, from the PHPStan of the project: what an expression is. The answers are those of the
 * text of the pass at its first question; a node inserted after it has none, and a rule asking for one asks with
 * `?->`.
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
	 * The scopes wait for the first question that needs them; the nodes are found by where their text stood now, so
	 * that what the pass changes meanwhile moves nothing.
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
				$offset = $child->getCurrentOffset();
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


	/**
	 * The parentheses around an expression are not a node of PHPStan, so they are answered by the expression inside.
	 * @return ?array{Expr, Scope}
	 */
	private function findExpression(ExpressionNode $node): ?array
	{
		$this->resolveScopes();
		while ($node instanceof ParenthesizedNode) {
			$node = $node->expression;
		}

		return $this->expressions[$node] ?? null;
	}


	/**
	 * The type of the expression as PHPStan sees it, with `$at` as it sees it where that node stands; null for an
	 * expression the analysis was made without.
	 */
	private function getType(ExpressionNode $expression, ?ExpressionNode $at = null): ?Type
	{
		$found = $this->findExpression($expression);
		$scope = $at === null ? $found[1] ?? null : $this->findExpression($at)[1] ?? null;
		return $found === null || $scope === null ? null : $scope->getType($found[0]);
	}


	/**
	 * Whether the expression is of the type, written in the syntax of PHPDoc as PHPStan reads it (`int|string`,
	 * `list<int>`, `non-empty-string`), its classes fully qualified; maybe where it may or may not be, and for an
	 * expression the analysis was made without. With `$at` the expression is asked about where that node stands, which
	 * a part of the variable of `isset()` needs: PHPStan takes it there as not null, since `isset()` would not fail on it.
	 */
	public function isOfType(ExpressionNode $expression, string $type, ?ExpressionNode $at = null): Tristate
	{
		$actual = $this->getType($expression, $at);
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
}
