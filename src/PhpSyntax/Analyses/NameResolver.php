<?php declare(strict_types=1);

namespace PhpSyntax\Analyses;

use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\GroupUseNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * Resolves names of classes, functions and constants the way PHP does: against the namespace and the imports
 * in effect at the node. An unqualified function or constant that is neither imported nor declared in the
 * namespace within the file is taken as global (the runtime fallback).
 */
final class NameResolver
{
	private const
		Classes = 'classes',
		Functions = 'functions',
		Constants = 'constants';

	private const SpecialClasses = ['self' => true, 'static' => true, 'parent' => true];

	/** @var array<int, string>  namespace name by the id of the namespace node, 0 for the file */
	private array $namespaces = [];

	/** @var array<int, array<string, array<string, string>>>  kind → alias → fully qualified name */
	private array $imports = [];

	/** @var array<int, array<string, true>>  kind:name of what is declared in the namespace within the file */
	private array $declared = [];


	public function __construct(
		private readonly FileNode $file,
	) {
	}


	/** Namespace of the node without a leading backslash, '' in the global namespace. */
	public function getNamespace(Node|Token $node): string
	{
		return $this->namespaces[$this->getScope($node)];
	}


	/** @return array<string, string>  lowercased alias → fully qualified name of the class imports in effect at the node */
	public function getClassImports(Node|Token $node): array
	{
		return $this->imports[$this->getScope($node)][self::Classes];
	}


	/** @return array<string, string>  lowercased alias → fully qualified name */
	public function getFunctionImports(Node|Token $node): array
	{
		return $this->imports[$this->getScope($node)][self::Functions];
	}


	/** @return array<string, string>  alias → fully qualified name, case-sensitive */
	public function getConstantImports(Node|Token $node): array
	{
		return $this->imports[$this->getScope($node)][self::Constants];
	}


	/** Fully qualified class name without a leading backslash; self, static and parent stay as they are. */
	public function resolveClass(NameNode $name): string
	{
		$parts = $name->getParts();
		if (count($parts) === 1 && isset(self::SpecialClasses[strtolower($parts[0])])) {
			return $parts[0];
		}

		return $this->resolve($name, self::Classes, caseSensitive: false, fallbackToGlobal: false);
	}


	public function resolveFunction(NameNode $name): string
	{
		return $this->resolve($name, self::Functions, caseSensitive: false, fallbackToGlobal: true);
	}


	public function resolveConstant(NameNode $name): string
	{
		return $this->resolve($name, self::Constants, caseSensitive: true, fallbackToGlobal: true);
	}


	/**
	 * Whether the node is a call of a global function, optionally of the given one.
	 */
	public function isGlobalFunctionCall(Node $node, ?string $name = null): bool
	{
		if (!$node instanceof FunctionCallNode || !$node->name instanceof NameNode || $node->name->isKeyword()) {
			return false;
		}

		$resolved = $this->resolveFunction($node->name);
		return !str_contains($resolved, '\\')
			&& ($name === null || strcasecmp($resolved, $name) === 0);
	}


	private function resolve(NameNode $name, string $kind, bool $caseSensitive, bool $fallbackToGlobal): string
	{
		$scope = $this->getScope($name);
		$namespace = $this->namespaces[$scope];
		$parts = $name->getParts();
		switch ($name->getKind()) {
			case NameKind::FullyQualified:
				return implode('\\', $parts);

			case NameKind::Relative:
				return self::prefix($namespace, implode('\\', $parts));

			case NameKind::Qualified:
				$alias = $this->imports[$scope][self::Classes][strtolower($parts[0])] ?? null;
				return $alias === null
					? self::prefix($namespace, implode('\\', $parts))
					: self::prefix($alias, implode('\\', array_slice($parts, 1)));

			default:
				$key = $caseSensitive ? $parts[0] : strtolower($parts[0]);
				$import = $this->imports[$scope][$kind][$key] ?? null;
				if ($import !== null) {
					return $import;
				} elseif ($namespace === '' || ($fallbackToGlobal && !isset($this->declared[$scope]["$kind:$key"]))) {
					return $parts[0];
				}

				return self::prefix($namespace, $parts[0]);
		}
	}


	private static function prefix(string $namespace, string $name): string
	{
		return $namespace === '' ? $name : "$namespace\\$name";
	}


	/** Returns the id of the scope of the node, building it on first use. */
	private function getScope(Node|Token $node): int
	{
		$namespace = $node instanceof NamespaceNode ? $node : $node->parent?->findAncestor(NamespaceNode::class);
		$id = $namespace ? spl_object_id($namespace) : 0;
		if (!isset($this->namespaces[$id])) {
			$this->buildScope($id, $namespace);
		}

		return $id;
	}


	private function buildScope(int $id, ?NamespaceNode $namespace): void
	{
		$this->namespaces[$id] = $namespace?->name ? implode('\\', $namespace->name->getParts()) : '';
		$this->imports[$id] = [self::Classes => [], self::Functions => [], self::Constants => []];
		$this->declared[$id] = [];

		foreach (($namespace->stmts ?? $this->file->stmts)->getItems() as $stmt) {
			if ($stmt instanceof UseNode) {
				foreach ($stmt->items->getItems() as $item) {
					$this->addImport($id, $item, self::importKind($stmt->type), '');
				}
			} elseif ($stmt instanceof GroupUseNode) {
				$prefix = implode('\\', $stmt->prefix->getParts());
				foreach ($stmt->items->getItems() as $item) {
					$this->addImport($id, $item, self::importKind($item->type ?? $stmt->type), $prefix);
				}
			} elseif ($stmt instanceof FunctionNode) {
				$this->declared[$id][self::Functions . ':' . strtolower($stmt->name->token->text)] = true;
			}
		}
	}


	private function addImport(int $id, UseItemNode $item, string $kind, string $prefix): void
	{
		$parts = $item->name->getParts();
		$target = self::prefix($prefix, implode('\\', $parts));
		$alias = $item->alias ? $item->alias->token->text : $parts[count($parts) - 1];
		$this->imports[$id][$kind][$kind === self::Constants ? $alias : strtolower($alias)] = $target;
	}


	private static function importKind(?Token $type): string
	{
		return match ($type?->kind) {
			TokenKind::Function => self::Functions,
			TokenKind::Const => self::Constants,
			default => self::Classes,
		};
	}
}
