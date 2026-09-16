<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules;

use DressCode\RuleContext;
use DressCode\Rules\Namespaces\ImportNotationRule;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{CommentPolicy, NameForm, Node, Parser, Printer, SymbolKind, Trivia, UnqualifiedResolution};
use PhpSyntax\Nodes\{AttributeAwareNode, AttributeGroupNode, ExpressionNode, FileNode, NameNode, Statement, UseItemNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, MethodCallNode, PropertyFetchNode, ShellExecNode, VariableNode};
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringNode, InterpolationNode};
use function count;


/**
 * What a rule writing code into a file needs so that the code takes the shape the file has: a class or a function
 * spelled the way the file reaches it, an import written the way the file writes its imports, an expression written in a string the
 * way its interpolation takes it, a node removed with one gap left of the two around it, an attribute on a line of its
 * own above a declaration. A rule shipped by a package writes with it too.
 */
final class CodeWriter
{
	/**
	 * How a class is written where the node stands: the shortest way that reaches it, through an import added where
	 * none does, the scope takes one and the short name is free; a file without a namespace imports a class of one
	 * too, rather than writing it qualified. A global class gets no import, it is written with its backslash where
	 * nothing imports it, which nameNotation spells as the project does. It may add an import, so it is called only
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
			&& $resolver->isAliasFree(substr($class, (int) strrpos('\\' . $class, '\\')), SymbolKind::ClassLike, $at)
		) {
			self::addImport($scope, SymbolKind::ClassLike, $class, $context);
			$short = $context->getAnalysis(NameResolver::class)->shortenName($class, SymbolKind::ClassLike, $at);
		}

		return $short;
	}


	/**
	 * How the name of another global function is written in place of the name of a call of a global one: bare where
	 * the replaced name is bare, nothing takes the bare name and it is no less certain than the replaced one, which is
	 * the fallback the call already stood on; else with the leading backslash.
	 */
	public static function spellFunction(string $function, NameNode $replaced, RuleContext $context): string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return $replaced->form === NameForm::Unqualified
			&& $resolver->isAliasFree($function, SymbolKind::Function, $replaced)
			&& (
				$resolver->getUnqualifiedResolution($replaced->text, SymbolKind::Function, $replaced) === UnqualifiedResolution::Uncertain
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
	 * Imports the name into the scope in the shape dresscode/importNotation gives its kind, or where that rule gives
	 * none the way the scope writes its imports, so that the rules of their shape and order find nothing to add: where
	 * the order of imports puts it (classes, functions, constants, each alphabetically the way orderedImports sorts by
	 * default, so other options of it may still find the order wrong), into a group use standing under the namespace of
	 * the name where that rule keeps groups, into the statement of its kind standing there when the shape is combined
	 * or that statement lists several names, else with a statement of its own, else first in the scope behind its
	 * `declare` statements, a blank line apart.
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
				$host = $host === null || $precedes(self::firstName($stmt)) ? $stmt : $host;
			} elseif (self::coversName($stmt, $fullName)) {
				$group = $group === null || $precedes(self::firstName($stmt)) ? $stmt : $group;
			}
		}

		$notation = $context->findRule(ImportNotationRule::class);
		if ($group !== null && ($notation?->keepsGroups() ?? true)) {
			$group->addImport($fullName, index: count(array_filter($group->items->getItems(), fn(UseItemNode $item) => $precedes($item->fullName))));
			return;
		}

		$shape = $notation?->getShape($kind);
		if ($host !== null && ($shape === 'combined' || ($shape === null && count($host->items) > 1))) {
			$host->addImport($fullName, index: count(array_filter($host->items->getItems(), fn(UseItemNode $item) => $precedes(trim((string) $item)))));
			return;
		}

		$after = $before = null;
		foreach ($items as $i => $stmt) {
			if (!$stmt instanceof Statement\UseNode) {
				continue;
			} elseif (
				$rank($stmt->symbolKind) < $rank($kind)
				|| ($stmt->symbolKind === $kind && $precedes(self::firstName($stmt)))
			) {
				$after = $i + 1;
			} else {
				$before ??= $i;
			}
		}

		$keyword = match ($kind) {
			SymbolKind::Function => 'function ',
			SymbolKind::Constant => 'const ',
			SymbolKind::ClassLike => '',
		};
		$statement = (new Parser)->parseStatement("use $keyword$fullName;");
		$eol = new Trivia(Trivia::LineEnding, $context->style->lineEnding);
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
			$statement->setEdgeTrivia($first->leadingTrivia ?? [], [$eol]);
			$first?->setLeadingTrivia($indent);
			$list->insert($before, $statement);
			return;
		}

		$index = 0;
		while (($items[$index] ?? null) instanceof Statement\DeclareNode) {
			$index++;
		}

		$neighborFirst = ($items[$index] ?? null)?->getFirstToken();
		$braced = $scope instanceof Statement\NamespaceNode && $scope->openBrace !== null;
		$indentation = $neighborFirst?->getIndentation() ?? ($braced ? $context->style->indent : '');
		// a braced namespace ends the line with its brace, an unbraced one and an open tag are a blank line apart from it
		$leading = $braced ? [] : [$eol];
		if ($index === 0 && $neighborFirst !== null) { // an open tag stays first
			foreach ($neighborFirst->leadingTrivia as $i => $trivia) {
				if ($trivia->id === Trivia::OpenTag) {
					$leading = [...array_slice($neighborFirst->leadingTrivia, 0, $i + 1), ...$leading];
					$neighborFirst->setLeadingTrivia(array_slice($neighborFirst->leadingTrivia, $i + 1));
					break;
				}
			}
		}

		if ($indentation !== '') {
			$leading[] = new Trivia(Trivia::Whitespace, $indentation);
		}

		$statement->setEdgeTrivia($leading, [$eol]);
		$list->insert($index, $statement);
		if ($neighborFirst !== null && ($neighborFirst->leadingTrivia[0] ?? null)?->id !== Trivia::LineEnding) {
			$neighborFirst->setBlankLinesBefore(1, $context->style->lineEnding);
		}
	}


	/** The name the order of the imports puts the statement by: the first one it imports, under the prefix of a group. */
	private static function firstName(Statement\UseNode $stmt): string
	{
		$item = $stmt->items->getItems()[0] ?? null;
		return $item === null
			? ''
			: ($stmt->isGroup() ? ltrim($stmt->prefix->text, '\\') . '\\' : '') . trim((string) $item);
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


	/**
	 * Whether `replaceExpression()` can write the expression in place of the node: one standing in an interpolation
	 * of a string takes a variable and what is read or called on it alone, and one inside a chain written without
	 * braces nothing else. For an expression that may stand in the interpolation of a string, which
	 * `ExpressionNode::replaceWithExpression()` does not handle; outside a string the two do the same.
	 */
	public static function canReplaceExpression(ExpressionNode $node, ExpressionNode $expression): bool
	{
		$interpolation = self::findInterpolation($node);
		return $interpolation === null
			|| (self::isInterpolable($expression) && ($interpolation[0] === 'braced' || $interpolation[1] === $node));
	}


	/**
	 * Writes the expression in place of the node, in braces where it stands in a string without them. For an
	 * expression that may stand in the interpolation of a string, which `ExpressionNode::replaceWithExpression()`
	 * does not handle; outside a string the two do the same.
	 */
	public static function replaceExpression(ExpressionNode $node, ExpressionNode $expression): void
	{
		if ((self::findInterpolation($node)[0] ?? null) !== 'bare') {
			$node->replaceWithExpression($expression);
			return;
		}

		$string = (new Parser)->parseExpression('"{' . Printer::print($expression) . '}"');
		assert($string instanceof InterpolatedStringNode);
		$node->replaceWith($string->parts->getItems()[0]->withoutEdgeTrivia());
	}


	/**
	 * How the node stands in a string, braced as `{$a->b}` or bare as `$a->b`, with the chain it heads there; null outside one.
	 * @return ?array{'braced'|'bare', Node}
	 */
	private static function findInterpolation(Node $node): ?array
	{
		while (
			($parent = $node->parent) instanceof PropertyFetchNode
			|| $parent instanceof MethodCallNode
			|| $parent instanceof ArrayAccessNode
		) {
			if (($parent instanceof ArrayAccessNode ? $parent->expression : $parent->object) !== $node) {
				return null;
			}

			$node = $parent;
		}

		return match (true) {
			$parent instanceof InterpolationNode => ['braced', $node],
			$parent?->parent instanceof InterpolatedStringNode,
			$parent?->parent instanceof HeredocNode,
			$parent?->parent instanceof ShellExecNode => ['bare', $node],
			default => null,
		};
	}


	/** Whether an interpolation takes the expression: a variable and what is read or called on it. */
	private static function isInterpolable(ExpressionNode $expression): bool
	{
		return match (true) {
			$expression instanceof VariableNode => true,
			$expression instanceof PropertyFetchNode, $expression instanceof MethodCallNode => self::isInterpolable($expression->object),
			$expression instanceof ArrayAccessNode => self::isInterpolable($expression->expression),
			default => false,
		};
	}


	/**
	 * Removes a node standing on lines of its own between two others, a member of a class among them, and leaves one
	 * gap where there were two, the narrower one, which `Node::remove()` would add up instead: none after the opening
	 * brace for the first member and before the closing one for the last. The comments of the node go where the policy
	 * says, to the next token by default.
	 */
	public static function removeBetweenGaps(
		Node $node,
		RuleContext $context,
		CommentPolicy $comments = CommentPolicy::MoveToNextToken,
	): void
	{
		$next = $node->getLastToken()?->getNext();
		$gap = $next !== null && $next->startsLine()
			? min($node->getFirstToken()?->countBlankLinesBefore() ?? 0, $next->countBlankLinesBefore())
			: null;
		$node->remove($comments);
		if ($next !== null && $gap !== null) {
			$next->setBlankLinesBefore($gap, $context->style->lineEnding);
		}
	}


	/**
	 * Writes the attributes above the declaration, each in a group on a line of its own, behind the attributes it
	 * carries already: the first one of a declaration without any takes over what stood in front of it, its doc
	 * comment among it. The code is that of an attribute without `#[]`, its class spelled already.
	 * @param  list<string>  $codes
	 */
	public static function addAttributes(AttributeAwareNode&Node $declaration, array $codes, RuleContext $context): void
	{
		$attributes = $declaration->attributes;
		// a trivia stands in one place, so each is made anew
		$first = $declaration->getFirstToken();
		$eolText = $context->style->lineEnding;
		$indentationText = $first?->getIndentation() ?? '';
		$indentation = fn() => new Trivia(Trivia::Whitespace, $indentationText);
		$takesOver = $attributes->isEmpty();
		foreach ($codes as $code) {
			$group = (new Parser)->parseFragment(AttributeGroupNode::class, "#[$code]");
			$attributes->append($group);
			if ($first === null) {
				continue;
			} elseif ($takesOver) {
				$group->getFirstToken()?->setLeadingTrivia($first->leadingTrivia);
				$first->setLeadingTrivia([$indentation()]);
				$takesOver = false;
			} else {
				$group->getFirstToken()?->setLeadingTrivia([$indentation()]);
			}

			$group->getLastToken()?->setTrailingTrivia([new Trivia(Trivia::LineEnding, $eolText)]);
		}
	}
}
