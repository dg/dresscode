<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, NameForm, Node, SymbolKind, Trivia, UnqualifiedResolution};
use PhpSyntax\Nodes\{AttributeAwareNode, AttributeGroupNode, ExpressionNode, FileNode, NameNode, Statement, UseItemNode};
use function count;


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: a class or a function
 * spelled the way the file reaches it, an import written the way the file writes its imports, an attribute on a line
 * of its own above a declaration. A rule shipped by a package writes with it too.
 */
final class CodeWriter
{
	/**
	 * How a class is written where the node stands: the shortest way that reaches it, through an import added where
	 * none does, the scope takes one and the short name is free; a file without a namespace imports a class of one
	 * too, rather than writing it qualified. A global class gets no import, it is written with its backslash where
	 * nothing imports it, which `qualification.global.class` decides. It may add an import, so it is called only
	 * after `report()` returned true.
	 */
	public static function writeClass(string $class, Node $at, RuleContext $context): string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$short = $resolver->shortenName($class, SymbolKind::ClassLike, $at);
		$scope = self::findImportScope($at);
		if (
			(str_starts_with($short, '\\') || ($scope instanceof FileNode && str_contains($short, '\\')))
			&& str_contains($class, '\\')
			&& $scope !== null
			&& self::canAddImport($scope)
			&& $resolver->isAliasFree(QualifiedNames::stripNamespace($class), SymbolKind::ClassLike, $at)
		) {
			self::addImport($scope, SymbolKind::ClassLike, $class, $context);
			$short = $context->getAnalysis(NameResolver::class)->shortenName($class, SymbolKind::ClassLike, $at);
		}

		return $short;
	}


	/**
	 * How the name of another global function is written in place of the name of a call of a global one: bare where
	 * the replaced name is bare, nothing takes the bare name and it is no less certain than the replaced one, which is
	 * the fallback the call already stood on; else, and for a name that is an expression, with the leading backslash.
	 */
	public static function spellFunction(string $function, NameNode|ExpressionNode $replaced, RuleContext $context): string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $replaced instanceof NameNode
			&& $replaced->form === NameForm::Unqualified
			&& $resolver->isAliasFree($function, SymbolKind::Function, $replaced)
			&& (
				$resolver->getUnqualifiedResolution($replaced) === UnqualifiedResolution::Uncertain
				|| $resolver->getUnqualifiedResolution($function, SymbolKind::Function, $replaced) === UnqualifiedResolution::Global
			)
				? $function
				: '\\' . $function;
	}


	/**
	 * The scope the imports of the node belong to: its namespace, or the file where the file declares none; null for
	 * a node of a file with namespaces that stands outside them.
	 */
	public static function findImportScope(Node $node): FileNode|Statement\NamespaceNode|null
	{
		for (; $node !== null; $node = $node->parent) {
			if ($node instanceof Statement\NamespaceNode) {
				return $node;
			} elseif ($node instanceof FileNode) {
				return array_any($node->statements->getItems(), fn(Node $stmt) => $stmt instanceof Statement\NamespaceNode) ? null : $node;
			}
		}

		return null;
	}


	/**
	 * Whether a `use` statement can be added to the scope: a file that opens with markup has no line for one unless
	 * an import stands in it already.
	 */
	public static function canAddImport(FileNode|Statement\NamespaceNode $scope): bool
	{
		$items = $scope->statements->getItems();
		$first = $items[0] ?? null;
		return !$first instanceof Statement\InlineHtmlNode
			|| $first->isPreamble()
			|| array_any($items, fn(Node $stmt) => $stmt instanceof Statement\UseNode);
	}


	/**
	 * Imports the name into the scope in the shape `Style::$imports` gives its kind, or where it gives none the way the
	 * scope writes its imports, so that the rules of their shape and order find nothing to add: where
	 * the order of imports puts it (classes, functions, constants, each alphabetically the way importOrder sorts by
	 * default, so other options of it may still find the order wrong), into a group use standing under the namespace of
	 * the name where the style keeps groups, into the statement of its kind standing there when the shape is combined
	 * or that statement lists several names, else with a statement of its own, else first in the scope behind its
	 * `declare` statements, a hashbang and the header comment of a file, a blank line apart.
	 */
	public static function addImport(
		FileNode|Statement\NamespaceNode $scope,
		SymbolKind $kind,
		string $fullName,
		RuleContext $context,
	): void
	{
		$rank = fn(SymbolKind $kind) => match ($kind) {
			SymbolKind::ClassLike => 0,
			SymbolKind::Function => 1,
			SymbolKind::Constant => 2,
		};
		$precedes = fn(string $name) => strcasecmp(strtr($name, ['\\' => ' ']), strtr($fullName, ['\\' => ' '])) < 0;
		$list = $scope->statements;
		$items = $list->getItems();
		// the statement the order of the imports puts the name into: the last one of the kind whose first name precedes it
		$host = $group = null;
		foreach ($items as $stmt) {
			if (!$stmt instanceof Statement\UseNode || $stmt->symbolKind !== $kind) {
				continue;
			} elseif (!$stmt->isGroup()) {
				$host = $host === null || $precedes(self::getFirstName($stmt)) ? $stmt : $host;
			} elseif (self::coversName($stmt, $fullName)) {
				$group = $group === null || $precedes(self::getFirstName($stmt)) ? $stmt : $group;
			}
		}

		$imports = $context->style->imports;
		if ($group !== null && $imports->keepsGroups()) {
			$group->addImport($fullName, index: count(array_filter($group->items->getItems(), fn(UseItemNode $item) => $precedes($item->fullName))));
			return;
		}

		$shape = $imports->getShape($kind);
		if ($host !== null && ($shape === 'combined' || ($shape === null && count($host->items) > 1))) {
			$host->addImport($fullName, index: count(array_filter($host->items->getItems(), fn(UseItemNode $item) => $precedes($item->text))));
			return;
		}

		$after = $before = null;
		foreach ($items as $i => $stmt) {
			if (!$stmt instanceof Statement\UseNode) {
				continue;
			} elseif (
				$rank($stmt->symbolKind) < $rank($kind)
				|| ($stmt->symbolKind === $kind && $precedes(self::getFirstName($stmt)))
			) {
				$after = $i + 1;
			} else {
				$before ??= $i;
			}
		}

		$keyword = self::spellImportKind($kind);
		$statement = (new Builder)->statement("use $keyword$fullName;");
		$eol = Trivia::fromText($context->style->lineEnding);
		$indentOf = fn(?Node $node): array => ($indentation = $node?->getFirstToken()?->getIndentation() ?? '') === ''
			? []
			: [new Trivia(Trivia::Whitespace, $indentation)];

		if ($after !== null) {
			$statement->setEdgeTrivia($indentOf($items[$after - 1]), [$eol]);
			$list->insert($after, $statement);
			return;
		}

		if ($before !== null) {
			// the import takes the place of the first one, with what stands above it, and that one keeps its indentation
			$first = $items[$before]->getFirstToken();
			$indent = $indentOf($items[$before]);
			$statement->setEdgeTrivia($first->leadingTrivia, [$eol]);
			$first->setLeadingTrivia($indent);
			$list->insert($before, $statement);
			return;
		}

		$index = 0;
		while (
			($stmt = $items[$index] ?? null) instanceof Statement\DeclareNode
			|| ($index === 0 && $stmt instanceof Statement\InlineHtmlNode && $stmt->isPreamble())
		) {
			$index++;
		}

		$neighborFirst = ($items[$index] ?? null)?->getFirstToken();
		$braced = $scope instanceof Statement\NamespaceNode && $scope->openBrace !== null;
		$indentation = $neighborFirst?->getIndentation() ?? ($braced ? $context->style->indent : '');
		// a braced namespace ends the line with its brace, an unbraced one and an open tag are a blank line apart from it
		$leading = $braced ? [] : [$eol];
		if ($neighborFirst !== null) {
			// the open tag stays first, and in a file so does its header, a comment a blank line apart from the code
			$trivia = $neighborFirst->leadingTrivia;
			$above = 0;
			foreach ($trivia as $i => $item) {
				if ($item->is(Trivia::OpenTag)) {
					$above = $i + 1;
				} elseif (
					$scope instanceof FileNode
					&& $item->isComment()
					&& ($trivia[$i + 1] ?? null)?->is(Trivia::LineEnding)
					&& ($trivia[$i + 2] ?? null)?->is(Trivia::LineEnding)
				) {
					$above = $i + 2;
				}
			}

			$leading = [...array_slice($trivia, 0, $above), ...$leading];
			$neighborFirst->setLeadingTrivia(array_slice($trivia, $above));
		}

		if ($indentation !== '') {
			$leading[] = new Trivia(Trivia::Whitespace, $indentation);
		}

		$statement->setEdgeTrivia($leading, [$eol]);
		$list->insert($index, $statement);
		if ($neighborFirst !== null && !($neighborFirst->leadingTrivia[0] ?? null)?->is(Trivia::LineEnding)) {
			$neighborFirst->setBlankLinesBefore(1, $context->style->lineEnding);
		}
	}


	/** The name the order of the imports puts the statement by: the first one it imports, under the prefix of a group. */
	private static function getFirstName(Statement\UseNode $stmt): string
	{
		$item = $stmt->items->getItems()[0] ?? null;
		return $item === null
			? ''
			: ($stmt->isGroup() ? ltrim($stmt->prefix->text, '\\') . '\\' : '') . $item->text;
	}


	/** Whether the prefix of the group is the namespace the name stands in, so that it can be written as an item of it. */
	private static function coversName(Statement\UseNode $stmt, string $fullName): bool
	{
		$name = ltrim($fullName, '\\');
		$pos = strrpos($name, '\\');
		return $stmt->isGroup()
			&& $pos !== false
			&& strcasecmp(ltrim($stmt->prefix->text, '\\'), substr($name, 0, $pos)) === 0;
	}


	/** The keyword a use statement writes in front of the names of the kind, with the space after it, empty for a class. */
	public static function spellImportKind(SymbolKind $kind): string
	{
		return match ($kind) {
			SymbolKind::Function => 'function ',
			SymbolKind::Constant => 'const ',
			SymbolKind::ClassLike => '',
		};
	}


	/** Whether the file imports a function under a name other than its own, so that a call names another function than it spells. */
	public static function importsFunctionAs(RuleContext $context): bool
	{
		/** @var \WeakMap<NameResolver, bool> $known  by the resolver, which the first mutation replaces */
		static $known = new \WeakMap;
		$resolver = $context->getAnalysis(NameResolver::class);
		if (isset($known[$resolver])) {
			return $known[$resolver];
		}

		$file = $context->file;
		$scopes = [$file, ...array_filter($file->statements->getItems(), fn($statement) => $statement instanceof Statement\NamespaceNode)];
		return $known[$resolver] = array_any($scopes, fn(Node $scope) => array_any(
			$resolver->getImports(SymbolKind::Function, $scope),
			fn(string $function, string $alias) => strcasecmp($alias, QualifiedNames::stripNamespace($function)) !== 0,
		));
	}


	/**
	 * Writes the attributes in front of the declaration, behind the attributes it carries already, each in a group on
	 * a line of its own where the declaration starts its line, else on the line of the declaration: the first one of a
	 * declaration without any takes over what stood in front of it, its doc comment among it. The code is that of an
	 * attribute without `#[]`, its class spelled already.
	 * @param  list<string>  $codes
	 */
	public static function addAttributes(AttributeAwareNode&Node $declaration, array $codes, RuleContext $context): void
	{
		$attributes = $declaration->attributes;
		$anchor = $attributes->isEmpty() ? $declaration->getFirstToken() : $attributes->getLastToken()?->getNext();
		$ownLine = $anchor?->startsLine() ?? false;
		$eolText = $context->style->lineEnding;
		$indentationText = $anchor?->getIndentation() ?? '';
		// a trivia stands in one place, so each is made anew
		$indentation = fn() => $indentationText === '' ? [] : [new Trivia(Trivia::Whitespace, $indentationText)];
		$takesOver = $attributes->isEmpty();
		foreach ($codes as $code) {
			$group = (new Builder)->fragment(AttributeGroupNode::class, "#[$code]");
			$attributes->append($group);
			if ($anchor === null) {
				continue;
			} elseif ($takesOver) {
				$group->getFirstToken()->setLeadingTrivia($anchor->leadingTrivia);
				$anchor->setLeadingTrivia($ownLine ? $indentation() : []);
				$takesOver = false;
			} else {
				$group->getFirstToken()->setLeadingTrivia($ownLine ? $indentation() : []);
			}

			$group->getLastToken()->setTrailingTrivia([Trivia::fromText($ownLine ? $eolText : ' ')]);
		}
	}
}
