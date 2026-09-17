<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{PhpDoc, Types};
use DressCode\{RuleContext, Tristate};
use DressCode\Rules\CodeWriter;
use PHPStan\PhpDocParser\Ast\{Attribute, Node as PhpDocAstNode};
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\{DoctrineArgument, DoctrineArrayItem};
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, PhpDocNode, PhpDocTagNode};
use PHPStan\PhpDocParser\Ast\Type\{ArrayShapeItemNode, IdentifierTypeNode, ObjectShapeItemNode};
use PHPStan\PhpDocParser\Lexer\Lexer;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, SymbolKind, Token, Trivia};
use PhpSyntax\Nodes\{FileNode, NameNode, SeparatedNodeList, UseItemNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, InterfaceNode, NamespaceNode};
use function count, is_array, strlen;


/**
 * What noDeprecatedClasses and replacedClasses share: every reference of a class, interface or enum in a scope of
 * imports rewritten to the name that replaces it, wherever the name stands, an import, a type, an instantiation,
 * a static access, an attribute. An import of the old name is rewritten in place where its alias or its short name goes on
 * naming the class, and the fully qualified references of the new class in the scope are then written by that name;
 * else it goes and the references import the new name the way the scope imports. A name in the list
 * of what a class implements or an interface extends that the list names already, as it stands or once rewritten, goes
 * from the list; one the types say cannot stand where it would, a class to implement or an interface to extend by a
 * class, is reported and left. Where the caller asks for them, the names in doc comments are rewritten too, token by
 * token so that the comment keeps its layout: a type, a class constant, the target of `@see` and of an inline tag.
 * @internal
 */
final class ClassReplacement
{
	private const ClassPattern = '\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*';


	/**
	 * Reports every reference of a class the closure has something to say about, and rewrites the ones it names
	 * a replacement for and the report allows.
	 * @param  \Closure(string): ?array{string, ?string}  $find  given a fully qualified class, the message and the class written instead, null for none; null for a class that is left alone
	 * @param  ?\Closure(string): ?array{string, ?string}  $findInDocs  the same as `$find` for a class a doc comment names; null where doc comments are left alone
	 */
	public static function apply(
		FileNode|NamespaceNode $scope,
		RuleContext $context,
		\Closure $find,
		?\Closure $findInDocs = null,
	): void
	{
		// everything is found before anything is rewritten: a rewritten import changes what the names below it resolve to
		$resolver = $context->getAnalysis(NameResolver::class);
		$types = $context->findAnalysis(Types::class);
		$imports = $references = $kept = $lists = $docs = [];
		if ($findInDocs !== null) {
			foreach (self::findDocReferences($scope, $context) as [$token, $trivia, $tokens, $names]) {
				$found = [];
				foreach ($names as [$index, $class]) {
					$result = $findInDocs($class);
					if ($result !== null) {
						$found[] = [$index, ...$result];
					}
				}

				if ($found !== []) {
					$docs[] = [$token, $trivia, $tokens, $found];
				}
			}
		}

		foreach ($scope->find(NameNode::class) as $name) {
			$item = $name->parent;
			if ($item instanceof UseItemNode) {
				$found = $item->symbolKind === SymbolKind::ClassLike ? $find($item->fullName) : null;
				if ($found !== null) {
					$imports[] = [$item, $item->fullName, ...$found];
				}

			} elseif ($name->symbolKind === SymbolKind::ClassLike && $name->isReference()) {
				$class = $resolver->resolveClass($name);
				$list = self::findInheritance($name);
				if ($list !== null) {
					$lists[spl_object_id($list)][strtolower($class)] = true;
				}

				$found = $find($class);
				$refusal = $found === null || $found[1] === null ? null : self::findRefusal($name, $found[1], $types);
				if ($found !== null) {
					$kept += $refusal === null ? [] : [strtolower($class) => true]; // the reference left as it is needs its import
					$references[] = [$name, ...$found, $refusal];
				}
			}
		}

		foreach ($imports as [$item, $class, $message, $new]) {
			if (isset($kept[strtolower($class)])) {
				continue; // the short name below goes on naming the class the import brings
			} elseif ($context->report($item->name, $message, fixable: $new !== null) && $new !== null) {
				$local = self::replaceImport($item, $new, $context);
				if ($local !== null) {
					self::shortenFullyQualified($scope, $new, $local);
				}
			}
		}

		foreach ($references as [$name, $message, $new, $refusal]) {
			$list = self::findInheritance($name);
			$named = $list === null || $new === null ? null : $lists[spl_object_id($list)] ?? [];
			if ($refusal !== null) {
				$context->report($name, $message . $refusal, fixable: false);

			} elseif (!$context->report($name, $message, fixable: $new !== null) || $new === null) {
				continue;

			} elseif ($list !== null && isset($named[strtolower($new)])) {
				// the list names the class already; what stood behind the last item stays behind the one before it
				$items = $list->getItems();
				if (end($items) === $name && count($items) > 1) {
					$items[count($items) - 2]->getLastToken()?->setTrailingTrivia($name->getLastToken()->trailingTrivia ?? []);
				}

				$list->removeItem($name);

			} else {
				$name->text = $name->form === NameForm::FullyQualified ? '\\' . $new : CodeWriter::writeClass($new, $name, $context);
				if ($list !== null) {
					$lists[spl_object_id($list)][strtolower($new)] = true;
				}
			}
		}

		foreach ($docs as [$token, $trivia, $tokens, $found]) {
			self::replaceInDocComment($token, $trivia, $tokens, $found, $context);
		}
	}


	/**
	 * The classes the doc comments of the scope name: in a type, in a class constant, as the target of a tag such as
	 * `@see` and of an inline tag such as `{@link}`.
	 * @return list<array{Token, Trivia, list<array{string, int, int}>, list<array{int, string}>}>  the token holding the doc
	 *   comment, the comment, its tokens, and per name the index of its token and the class
	 */
	private static function findDocReferences(FileNode|NamespaceNode $scope, RuleContext $context): array
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$result = [];
		for ($token = $scope->getFirstToken(), $last = $scope->getLastToken(); $token !== null; $token = $token->getNext()) {
			foreach ([...$token->leadingTrivia, ...$token->trailingTrivia] as $trivia) {
				if ($trivia->id !== Trivia::DocComment || $trivia->inInterpolation) {
					continue;
				}

				$tokens = $phpDoc->getTokens($trivia);
				$names = [];
				foreach (self::findDocNames($phpDoc->parse($trivia), $tokens) as $index) {
					$names[] = [$index, $resolver->resolveClass(NameNode::fromText($tokens[$index][0]), $token)];
				}

				if ($names !== []) {
					$result[] = [$token, $trivia, $tokens, $names];
				}
			}

			if ($token === $last) {
				break;
			}
		}

		return $result;
	}


	/**
	 * The indexes of the tokens of a doc comment that name a class: in a type, in a class constant, as the target of
	 * a tag the parser knows no type for, `@see Foo::bar()`, and of every inline tag, `{@link Foo}`.
	 * @param  list<array{string, int, int}>  $tokens
	 * @return list<int>
	 */
	private static function findDocNames(PhpDocNode $tree, array $tokens): array
	{
		$type = fn(int $index) => $tokens[$index][Lexer::TYPE_OFFSET] ?? null;
		// the target of a tag stands after it on the same line
		$target = fn(int $tag) => $type($tag + 1) === Lexer::TOKEN_HORIZONTAL_WS ? $tag + 2 : -1;
		$indexes = [];
		$walk = function (PhpDocAstNode $node) use (&$walk, &$indexes, $target): void {
			$index = $node->getAttribute(Attribute::START_INDEX);
			if ($node instanceof IdentifierTypeNode || ($node instanceof ConstFetchNode && $node->className !== '')) {
				$indexes[] = $index;
			} elseif ($node instanceof PhpDocTagNode && $node->value instanceof GenericTagValueNode) {
				$indexes[] = $target($index);
			}

			foreach (get_object_vars($node) as $value) {
				foreach (is_array($value) ? $value : [$value] as $child) {
					// the key of a shape and of a Doctrine argument or array item is a name, not a type
					if (
						$child instanceof PhpDocAstNode
						&& !(($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) && $child === $node->keyName)
						&& !(($node instanceof DoctrineArgument || $node instanceof DoctrineArrayItem) && $child === $node->key && $child instanceof IdentifierTypeNode)
					) {
						$walk($child);
					}
				}
			}
		};
		$walk($tree);

		foreach ($tokens as $index => $token) {
			if ($token[Lexer::TYPE_OFFSET] === Lexer::TOKEN_OPEN_CURLY_BRACKET && $type($index + 1) === Lexer::TOKEN_PHPDOC_TAG) {
				$indexes[] = $target($index + 1);
			}
		}

		return array_values(array_unique(array_filter($indexes, fn(int $index) => $type($index) === Lexer::TOKEN_IDENTIFIER
			&& preg_match('~^' . self::ClassPattern . '$~D', $tokens[$index][Lexer::VALUE_OFFSET]))));
	}


	/**
	 * Reports every name of a doc comment, and writes the class that replaces it where the report allows, token by
	 * token, so that the rest of the comment stays as it is written.
	 * @param  list<array{string, int, int}>  $tokens
	 * @param  list<array{int, string, ?string}>  $found  per name the index of its token, the message and the class written instead
	 */
	private static function replaceInDocComment(Token $token, Trivia $trivia, array $tokens, array $found, RuleContext $context): void
	{
		$changed = false;
		$unfixable = [];
		foreach ($found as [$index, $message, $new]) {
			if ($new === null || $token->parent === null) {
				$unfixable[] = $message; // reported once the comment is written, nothing changing after them
			} elseif ($context->report($token, $message, trivia: $trivia)) {
				$name = $tokens[$index][Lexer::VALUE_OFFSET];
				$tokens[$index][Lexer::VALUE_OFFSET] = str_starts_with($name, '\\') ? '\\' . $new : CodeWriter::writeClass($new, $token->parent, $context);
				$changed = true;
			}
		}

		if ($changed) {
			$written = $trivia->withText(implode('', array_column($tokens, Lexer::VALUE_OFFSET)));
			$replace = fn(Trivia $item) => $item === $trivia ? $written : $item;
			$token->setLeadingTrivia(array_map($replace, $token->leadingTrivia))->setTrailingTrivia(array_map($replace, $token->trailingTrivia));
		}

		foreach ($unfixable as $message) {
			$context->report($token, $message, trivia: $trivia, fixable: false);
		}
	}


	/**
	 * The list of what a class or an enum implements, or of what an interface extends, that holds the name.
	 * @return ?SeparatedNodeList<NameNode>
	 */
	private static function findInheritance(NameNode $name): ?SeparatedNodeList
	{
		$list = $name->parent;
		$declaration = $list?->parent;
		return $list instanceof SeparatedNodeList && (
			(($declaration instanceof ClassNode || $declaration instanceof EnumNode) && $declaration->implements === $list)
			|| ($declaration instanceof InterfaceNode && $declaration->extends === $list)
		) ? $list : null;
	}


	/** Why the class cannot stand where the name does, as a clause of the message; null where it can or the types cannot tell. */
	private static function findRefusal(NameNode $name, string $new, ?Types $types): ?string
	{
		$isInterface = $types?->isInterface($new);
		return match (true) {
			$isInterface === Tristate::No && self::findInheritance($name) !== null => ", but `$new` is a class, which is not implemented",
			$isInterface === Tristate::Yes && $name->parent instanceof ClassNode && $name->parent->extends === $name => ", but `$new` is an interface, which a class does not extend",
			default => null,
		};
	}


	/**
	 * The import stays where its alias or its short name goes on naming the class and, in a group, the prefix still
	 * names the namespace; else it goes and the references import the new name anew.
	 * @return ?string  the name the import stays under, null where it went
	 */
	private static function replaceImport(UseItemNode $item, string $new, RuleContext $context): ?string
	{
		$stmt = $item->getStatement();
		if ($stmt === null) {
			return null;
		}

		$prefix = $stmt->isGroup() ? ltrim($stmt->prefix->text, '\\') . '\\' : '';
		$short = self::shortName($new);
		if (
			str_starts_with(strtolower($new), strtolower($prefix))
			&& ($item->alias !== null
				|| strcasecmp($item->name->shortName, $short) === 0
				|| $context->getAnalysis(NameResolver::class)->isAliasFree($short, SymbolKind::ClassLike, $item))
		) {
			$item->name->text = substr($new, strlen($prefix));
			return $item->alias->text ?? $short;
		} elseif (count($stmt->items) === 1) {
			$stmt->remove();
		} else {
			$stmt->items->removeItem($item);
		}

		return null;
	}


	/**
	 * The fully qualified references of the class in the scope written by the name its import brings: a rule that
	 * wrote the class while the name still stood for the replaced one had to write it in full.
	 */
	private static function shortenFullyQualified(FileNode|NamespaceNode $scope, string $class, string $local): void
	{
		foreach ($scope->find(NameNode::class) as $name) {
			if (
				$name->symbolKind === SymbolKind::ClassLike
				&& $name->isReference()
				&& $name->form === NameForm::FullyQualified
				&& strcasecmp(ltrim($name->text, '\\'), $class) === 0
			) {
				$name->text = $local;
			}
		}
	}


	private static function shortName(string $class): string
	{
		return substr($class, (int) strrpos('\\' . $class, '\\'));
	}
}
