<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpDoc;
use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PHPStan\PhpDocParser\Ast\Node as PhpDocAstNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count, is_array;


/**
 * No import that the code (or, optionally, a doc comment) does not use.
 */
#[RuleInfo(
	'dresscode/unused-imports',
	Stage::Structure,
	description: 'Removes imports that nothing uses',
)]
final class UnusedImportsRule extends NodeRule implements ConfigurableRule
{
	private const Classes = 'class';
	private const Functions = 'function';
	private const Constants = 'const';

	private bool $searchAnnotations = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'searchAnnotations' => Expect::bool(true)->description('A class name referenced in a doc comment counts as used'),
		]);
	}


	public function configure(array $options): void
	{
		$this->searchAnnotations = $options['searchAnnotations'];
	}


	public function getVisitedTypes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FileNode && !$node instanceof NamespaceNode) {
			return;
		}

		$imports = [];
		foreach ($node->statements->getItems() as $stmt) {
			if ($stmt instanceof UseNode) {
				foreach ($stmt->items->getItems() as $item) {
					$imports[] = [$stmt, $item, self::kindOf($item->kind)];
				}
			}
		}

		if (!$imports) {
			return;
		}

		$used = $this->collectUsages($node, $context);
		foreach ($imports as [$stmt, $item, $kind]) {
			$alias = $item->alias === null ? $item->name->shortName : $item->alias->text;
			$key = $kind === self::Constants ? $alias : strtolower($alias);
			if (isset($used[$kind][$key]) || !$context->report($item, "The import of '$alias' is unused")) {
				continue;
			}

			if (count($stmt->items) === 1) {
				$stmt->remove();
			} else {
				$stmt->items->removeItem($item);
			}
		}
	}


	/**
	 * @return array<string, array<string, true>>  kind → alias (lowercased except constants) → used
	 */
	private function collectUsages(FileNode|NamespaceNode $scope, RuleContext $context): array
	{
		$used = [self::Classes => [], self::Functions => [], self::Constants => []];
		foreach ($scope->find(NameNode::class) as $name) {
			$parent = $name->parent;
			if (
				$parent instanceof UseItemNode
				|| $parent instanceof UseNode
				|| $parent instanceof NamespaceNode
				|| $name->kind === NameKind::FullyQualified
				|| $name->kind === NameKind::Relative
				|| ($scope instanceof FileNode && $name->findAncestor(NamespaceNode::class))
			) {
				continue;
			}

			$parts = $name->parts;
			if (count($parts) > 1) {
				$used[self::Classes][strtolower($parts[0])] = true;
			} elseif ($parent instanceof FunctionCallNode && $parent->name === $name) {
				$used[self::Functions][strtolower($parts[0])] = true;
			} elseif ($parent instanceof ConstantFetchNode) {
				$used[self::Constants][$parts[0]] = true;
			} else {
				$used[self::Classes][strtolower($parts[0])] = true;
			}
		}

		if ($this->searchAnnotations) {
			$phpDoc = $context->getAnalysis(PhpDoc::class);
			foreach (self::docComments($scope) as $trivia) {
				foreach (self::identifiers($phpDoc->parse($trivia)) as $identifier) {
					if ($identifier !== '' && $identifier[0] !== '\\' && $identifier[0] !== '$') {
						$used[self::Classes][strtolower(explode('\\', $identifier)[0])] = true;
					}
				}
			}
		}

		return $used;
	}


	/** @return list<Trivia> */
	private static function docComments(FileNode|NamespaceNode $scope): array
	{
		$result = [];
		$first = $scope->getFirstToken();
		$last = $scope->getLastToken();
		for ($token = $first; $token !== null; $token = $token->getNext()) {
			foreach ([...$token->leadingTrivia, ...$token->trailingTrivia] as $trivia) {
				if ($trivia->kind === TriviaKind::DocComment) {
					$result[] = $trivia;
				}
			}

			if ($token === $last) {
				break;
			}
		}

		return $result;
	}


	/** @return list<string> */
	private static function identifiers(PhpDocAstNode $node): array
	{
		$result = [];
		if ($node instanceof IdentifierTypeNode) {
			$result[] = $node->name;
		} elseif ($node instanceof GenericTagValueNode) {
			// a tag the parser knows no type for, such as `@see Foo::bar()`; the target is the first word
			$result = self::references($node->value, target: true);
		} elseif ($node instanceof PhpDocTextNode) {
			$result = self::references($node->text, target: false);
		}

		foreach (get_object_vars($node) as $value) {
			foreach (is_array($value) ? $value : [$value] as $child) {
				if ($child instanceof PhpDocAstNode) {
					$result = [...$result, ...self::identifiers($child)];
				}
			}
		}

		return $result;
	}


	/**
	 * The names a piece of a doc comment the parser left as text refers to: the target of every inline tag
	 * (`{@link Foo}`) and, when the piece is the value of a tag, the word it starts with. A member behind
	 * `::` and the parentheses of a method are cut off, so that `Foo::bar()` counts as a use of `Foo`.
	 * @return list<string>
	 */
	private static function references(string $text, bool $target): array
	{
		$name = '\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*';
		$result = [];
		if ($target && preg_match("~^$name~", $text, $m)) {
			$result[] = $m[0];
		}

		preg_match_all("~\\{@\\w+\\s+($name)~", $text, $matches);
		return [...$result, ...$matches[1]];
	}


	private static function kindOf(SymbolKind $kind): string
	{
		return match ($kind) {
			SymbolKind::Function => self::Functions,
			SymbolKind::Constant => self::Constants,
			SymbolKind::ClassLike => self::Classes,
		};
	}
}
