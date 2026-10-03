<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\PhpDoc;
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleGroup, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PHPStan\PhpDocParser\Ast\Node as PhpDocAstNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineAnnotation;
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, PhpDocTagNode, PhpDocTextNode};
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PhpSyntax\{NameForm, Node, SymbolKind, Token, Trivia};
use PhpSyntax\Nodes\{FileNode, NameNode};
use PhpSyntax\Nodes\Statement\{NamespaceNode, UseNode};
use function count, is_array;


/**
 * No import that the code (or, optionally, a doc comment) does not use.
 */
#[RuleInfo(
	'dresscode/unusedImports',
	Stage::Structure,
	description: 'Removes imports that nothing uses',
	group: RuleGroup::Cleanup,
)]
final class UnusedImportsRule extends NodeRule implements ConfigurableRule
{
	private const Classes = 'class';
	private const Functions = 'function';
	private const Constants = 'const';

	private bool $annotations = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'annotations' => Expect::bool(true)->description('A class name referenced in a doc comment counts as used, the name of an annotation such as `@DB\Entity` included'),
		]);
	}


	public function configure(array $options): void
	{
		$this->annotations = $options['annotations'];
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
					$kind = self::kindOf($item->symbolKind);
					$alias = $item->alias === null ? $item->name->shortName : $item->alias->text;
					$imports[] = [$item, $kind, $alias, $kind === self::Constants ? $alias : strtolower($alias)];
				}
			}
		}

		if (!$imports) {
			return;
		}

		$used = self::collectUsages($node);
		// a doc comment can use only a class, so it is read when the code leaves an imported class unused
		if (
			$this->annotations
			&& array_any($imports, fn(array $import) => $import[1] === self::Classes && !isset($used[self::Classes][$import[3]]))
		) {
			$used[self::Classes] += self::collectAnnotationUsages($node, $context);
		}

		foreach ($imports as [$item, $kind, $alias, $key]) {
			$shown = $kind === self::Functions ? "$alias()" : $alias;
			if (isset($used[$kind][$key]) || !$context->report($item, "The import of `$shown` is unused")) {
				continue;
			}

			$item->remove();
		}
	}


	/**
	 * @return array<string, array<string, true>>  kind => alias (lowercased except constants) => used
	 */
	private static function collectUsages(FileNode|NamespaceNode $scope): array
	{
		$used = [self::Classes => [], self::Functions => [], self::Constants => []];
		foreach ($scope->find(NameNode::class) as $name) {
			if (
				$name->isDeclaration()
				|| $name->form === NameForm::FullyQualified
				|| $name->form === NameForm::Relative
				|| ($scope instanceof FileNode && $name->findAncestor(NamespaceNode::class))
			) {
				continue;
			}

			$parts = $name->parts;
			$kind = count($parts) > 1 ? self::Classes : self::kindOf($name->symbolKind);
			$used[$kind][$kind === self::Constants ? $parts[0] : strtolower($parts[0])] = true;
		}

		return $used;
	}


	/**
	 * @return array<string, true>  lowercased alias of a class a doc comment refers to => used
	 */
	private static function collectAnnotationUsages(FileNode|NamespaceNode $scope, RuleContext $context): array
	{
		$used = [];
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		foreach (self::docComments($scope) as $trivia) {
			foreach (self::identifiers($phpDoc->parse($trivia)) as $identifier) {
				if ($identifier !== '' && $identifier[0] !== '\\' && $identifier[0] !== '$') {
					$used[strtolower(explode('\\', $identifier)[0])] = true;
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
				if ($trivia->id === Trivia::DocComment) {
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
		} elseif (
			($node instanceof PhpDocTagNode || $node instanceof DoctrineAnnotation)
			&& preg_match('~^@([A-Z]|.*\\\\)~', $node->name)
		) {
			// an annotation such as `@DB\Entity`, `@Endpoint` or `@Index` nested in another, unlike `@param` or `@phpstan-return`
			$result[] = substr($node->name, 1);
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
