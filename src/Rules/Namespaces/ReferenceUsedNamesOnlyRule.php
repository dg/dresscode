<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Parser;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function array_slice, count;


/**
 * Class names from other namespaces are imported and referenced by the import, not written fully
 * qualified in the code; names of the global namespace may stay fully qualified, and so may a name
 * whose import would collide with another one. Functions and constants are never imported.
 */
#[RuleInfo(
	'dresscode/reference-used-names-only',
	Stage::Structure,
	description: 'Imports fully qualified names instead of referencing them in place',
)]
final class ReferenceUsedNamesOnlyRule extends NodeRule implements ConfigurableRule
{
	private const Classes = 'class';
	private const Functions = 'function';
	private const Constants = 'const';

	private bool $allowFullyQualifiedGlobalNames = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'allowFullyQualifiedGlobalNames' => Expect::bool(true)
				->description('A class of the global namespace, such as \Exception, may stay fully qualified instead of being imported'),
		]);
	}


	public function configure(array $options): void
	{
		$this->allowFullyQualifiedGlobalNames = $options['allowFullyQualifiedGlobalNames'];
	}


	public function getVisitedTypes(): array
	{
		return [Nodes\FileNode::class, Statement\NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Nodes\FileNode && !$node instanceof Statement\NamespaceNode) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$imports = $this->collectImports($node);
		$declared = $this->collectDeclarations($node);
		$targets = $this->collectTargets($node, $resolver);
		$canAddImport = self::canAddImport($node);
		foreach ($node->find(Nodes\NameNode::class) as $name) {
			$kind = self::kindOf($name);
			if (
				$kind !== self::Classes // functions and constants are referenced fully qualified, never imported
				|| $name->kind !== NameKind::FullyQualified
				|| ($node instanceof Nodes\FileNode && $name->findAncestor(Statement\NamespaceNode::class))
			) {
				continue;
			}

			$parts = $name->parts;
			$fqn = implode('\\', $parts);
			if (count($parts) === 1 && $this->allowFullyQualifiedGlobalNames) {
				continue;
			}

			$alias = $name->shortName;
			$key = strtolower($alias);
			$existing = $imports[$kind][$key] ?? null;
			if ($existing !== null && strcasecmp($existing, $fqn) !== 0) {
				continue; // another name is imported under this alias
			}

			if ($this->shadowsGlobalTarget($targets[$kind][$key] ?? [], $fqn)) {
				continue; // the bare name also refers to a global one in this scope, an import would shadow it
			}

			if (
				$existing === null
				&& (
					!$canAddImport
					|| isset($declared[$kind][$key])
					|| $this->shadowsOwnNamespace($alias, $resolver->getNamespace($name), $declared)
				)
			) {
				continue;
			}

			if (!$context->report($name, "The fully qualified name \\$fqn must be imported")) {
				continue;
			}

			if ($existing === null) {
				$this->addImport($node, $kind, $fqn, $context);
				$imports[$kind][$key] = $fqn;
			}

			$token = new Token(TokenKind::Identifier, $alias);
			$token->setLeadingTrivia($name->token->leadingTrivia);
			$token->setTrailingTrivia($name->token->trailingTrivia);
			$name->token = $token;
		}
	}


	/**
	 * @return array<string, array<string, string>>  kind → alias (lowercased except constants) → fully qualified name
	 */
	private function collectImports(Nodes\FileNode|Statement\NamespaceNode $scope): array
	{
		$imports = [self::Classes => [], self::Functions => [], self::Constants => []];
		foreach ($scope->statements->getItems() as $stmt) {
			if ($stmt instanceof Statement\UseNode) {
				foreach ($stmt->items->getItems() as $item) {
					$kind = self::importKind($item->kind);
					$alias = $item->alias === null ? $item->name->shortName : $item->alias->text;
					$imports[$kind][$kind === self::Constants ? $alias : strtolower($alias)] = $item->fullName;
				}
			}
		}

		return $imports;
	}


	/**
	 * What each bare last part of a referenced name resolves to in the scope; more than one target
	 * for the same part means an import of that part would shadow one of them.
	 * @return array<string, array<string, array<string, true>>>  kind → bare name → resolved names
	 */
	private function collectTargets(Nodes\FileNode|Statement\NamespaceNode $scope, NameResolver $resolver): array
	{
		$targets = [self::Classes => [], self::Functions => [], self::Constants => []];
		foreach ($scope->find(Nodes\NameNode::class) as $name) {
			$kind = self::kindOf($name);
			if ($kind === null) {
				continue;
			}

			$alias = $name->shortName;
			$key = $kind === self::Constants ? $alias : strtolower($alias);
			$resolved = match ($kind) {
				self::Functions => $resolver->resolveFunction($name),
				self::Constants => $resolver->resolveConstant($name),
				default => $resolver->resolveClass($name),
			};
			$targets[$kind][$key][strtolower(ltrim($resolved, '\\'))] = true;
		}

		return $targets;
	}


	/** @param array<string, true> $targets */
	private function shadowsGlobalTarget(array $targets, string $fqn): bool
	{
		foreach (array_keys($targets) as $resolved) {
			if (strcasecmp($resolved, ltrim($fqn, '\\')) !== 0 && !str_contains($resolved, '\\')) {
				return true;
			}
		}

		return false;
	}


	/**
	 * @return array<string, array<string, true>>  kind → name declared in the scope
	 */
	private function collectDeclarations(Nodes\FileNode|Statement\NamespaceNode $scope): array
	{
		$declared = [self::Classes => [], self::Functions => [], self::Constants => []];
		foreach ($scope->statements->getItems() as $stmt) {
			if ($stmt instanceof Nodes\ClassLikeNode && isset($stmt->name)) {
				$declared[self::Classes][strtolower((string) $stmt->name->token->text)] = true;
			} elseif ($stmt instanceof Statement\FunctionNode) {
				$declared[self::Functions][strtolower($stmt->name->token->text)] = true;
			}
		}

		return $declared;
	}


	/** @param array<string, array<string, true>> $declared */
	private function shadowsOwnNamespace(string $alias, string $namespace, array $declared): bool
	{
		return $namespace !== '' && isset($declared[self::Classes][strtolower($alias)]);
	}


	/**
	 * A file that opens with markup has no line for a new import unless one stands in it already.
	 */
	private static function canAddImport(Nodes\FileNode|Statement\NamespaceNode $scope): bool
	{
		$items = $scope->statements->getItems();
		$first = $items[0] ?? null;
		if (!$first instanceof Statement\InlineHtmlNode || $first->isPreamble()) {
			return true;
		}

		foreach ($items as $stmt) {
			if ($stmt instanceof Statement\UseNode) {
				return true;
			}
		}

		return false;
	}


	private function addImport(
		Nodes\FileNode|Statement\NamespaceNode $scope,
		string $kind,
		string $fqn,
		RuleContext $context,
	): void
	{
		$list = $scope->statements;
		$items = $list->getItems();
		$index = null;
		foreach ($items as $i => $stmt) {
			if ($stmt instanceof Statement\UseNode) {
				$index = $i + 1;
			}
		}

		$statement = (new Parser)->parseStatement('use ' . ($kind === self::Classes ? '' : "$kind ") . $fqn . ';');
		$eol = new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol);
		if ($index !== null) {
			$indentation = $items[$index - 1]->getFirstToken()?->getIndentation() ?? '';
			$statement->setEdgeTrivia($indentation === '' ? [] : [new Trivia(TriviaKind::Whitespace, $indentation)], [$eol]);
			$list->insert($index, $statement);
			return;
		}

		$start = ($items[0] ?? null) instanceof Statement\InlineHtmlNode && $items[0]->isPreamble() ? 1 : 0;
		$index = $start;
		while (
			($items[$index] ?? null) instanceof Statement\DeclareNode
			|| (
				$scope instanceof Nodes\FileNode
				&& ($items[$index] ?? null) instanceof Statement\NamespaceNode
			)
		) {
			$index++;
		}

		$neighbor = $items[$index] ?? null;
		$indentation = $neighbor?->getFirstToken()?->getIndentation() ?? ($scope instanceof Statement\NamespaceNode && $scope->openBrace ? $context->getStyle()->indent : '');
		$leading = $index > $start || $scope instanceof Statement\NamespaceNode ? [$eol] : [];
		$neighborFirst = $neighbor?->getFirstToken();
		if ($index === $start && $neighborFirst !== null) { // the open tag stays first
			foreach ($neighborFirst->leadingTrivia as $i => $trivia) {
				if ($trivia->kind === TriviaKind::OpenTag) {
					$leading = [...array_slice($neighborFirst->leadingTrivia, 0, $i + 1), ...$leading];
					$neighborFirst->setLeadingTrivia(array_slice($neighborFirst->leadingTrivia, $i + 1));
					break;
				}
			}
		}

		if ($indentation !== '') {
			$leading[] = new Trivia(TriviaKind::Whitespace, $indentation);
		}

		$statement->setEdgeTrivia($leading, [$eol]);
		$list->insert($index, $statement);
		if ($neighbor !== null && !self::hasBlankLine($neighbor)) {
			$neighbor->getFirstToken()?->setBlankLinesBefore(1, $context->getStyle()->eol);
		}
	}


	private static function hasBlankLine(Node $node): bool
	{
		$first = $node->getFirstToken();
		return ($first?->leadingTrivia[0] ?? null)?->kind === TriviaKind::EndOfLine;
	}


	/**
	 * The kind of import a name would need, or null for a name that is not a reference.
	 */
	private static function kindOf(Nodes\NameNode $name): ?string
	{
		return $name->isDeclaration() ? null : self::importKind($name->role);
	}


	private static function importKind(SymbolKind $kind): string
	{
		return match ($kind) {
			SymbolKind::Function => self::Functions,
			SymbolKind::Constant => self::Constants,
			SymbolKind::ClassLike => self::Classes,
		};
	}
}
