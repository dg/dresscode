<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberKind, PhpDoc, Types};
use DressCode\{Risk, RuleContext, Tristate};
use DressCode\Rules\{CodeWriter, QualifiedNames};
use PHPStan\PhpDocParser\Ast\{Attribute, Node as PhpDocAstNode};
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\{DoctrineArgument, DoctrineArrayItem};
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, PhpDocNode, PhpDocTagNode};
use PHPStan\PhpDocParser\Ast\Type\{ArrayShapeItemNode, IdentifierTypeNode, ObjectShapeItemNode, UnionTypeNode as PhpDocUnionTypeNode};
use PHPStan\PhpDocParser\Lexer\Lexer;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameForm, Node, SymbolKind, Token, Trivia};
use PhpSyntax\Nodes\{ClassLikeNode, FileNode, NameNode, SeparatedNodeList, UseItemNode};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, FunctionCallNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, InterfaceNode, NamespaceNode};
use PhpSyntax\Nodes\Type\{NamedTypeNode, UnionTypeNode};
use function count, is_array, strlen;


/**
 * What noDeprecatedClasses and replacedClasses share: every reference of a class, interface or enum in a scope of
 * imports rewritten to the name that replaces it, wherever the name stands, an import, a type, an instantiation,
 * a static access, an attribute. An import of the old name is rewritten in place where its alias or its short name goes on
 * naming the class, and the fully qualified references of the new class in the scope are then written by that name;
 * else it goes and the references import the new name the way the scope imports. A name in the list
 * of what a class implements or an interface extends, or in a union type, that the list names already, as it stands
 * or once rewritten, goes from the list; one the types say cannot stand where it would, a class to implement or an
 * interface to extend by a class, is reported and left. A name that keeps the old one alive is left as it is: the class
 * `class_alias()` declares and the one `class_exists()` and its kin ask for, and the name the replacement itself
 * extends or implements, which rewritten would name the class itself. The names in doc comments are rewritten too, token by token
 * so that the comment keeps its layout: a type, a class constant, the target of `@see` and of an inline tag.
 * @internal
 */
final class ClassReplacement
{
	private const ClassPattern = '\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*';


	/**
	 * Reports every reference of a class the closure has something to say about, and rewrites the ones it names
	 * a replacement for and the report allows.
	 * @param  \Closure(string): ?array{0: string, 1: ?string, 2?: ?string}  $find  given a fully qualified class, the message,
	 *   the class written instead, null for none, and why writing it may change what the code does, which makes the fix
	 *   risky; null for a class that is left alone
	 * @param  \Closure(string, ?array{string, MemberKind}, Token, list<string>): (array{0: string, 1: ?string, 2?: ?string}|false|null)  $findInDocs
	 *   the same as `$find` for a class a doc comment names, with the member it names, the token holding the comment and
	 *   the lowercased classes beside it in a union type, false for one that goes on naming the class and keeps its import
	 * @param  ?\Closure(NameNode): bool  $keeps  whether the reference goes on naming the class, which nothing is said
	 *   about and whose import stays with it
	 * @param  ?AnnotationMap  $annotations  the map of annotations read as attributes, whose annotations keep the import
	 *   of their class until they are attributes
	 */
	public static function rewriteReferences(
		FileNode|NamespaceNode $scope,
		RuleContext $context,
		\Closure $find,
		\Closure $findInDocs,
		?\Closure $keeps = null,
		?AnnotationMap $annotations = null,
	): void
	{
		// everything is found before anything is rewritten: a rewritten import changes what the names below it resolve to
		$resolver = $context->getAnalysis(NameResolver::class);
		$types = $context->findAnalysis(Types::class);
		$imports = $references = $kept = $lists = $docs = [];
		foreach (self::findDocReferences($scope, $context) as [$token, $trivia, $tokens, $names]) {
			$found = [];
			foreach ($names as [$index, $class, $member, $union]) {
				$result = $findInDocs($class, $member, $token, $union);
				if ($result === false) {
					$kept[strtolower($class)] = true;
				} elseif ($result !== null) {
					$found[] = [
						$index,
						$result[0],
						$result[1],
						$result[2] ?? null,
						$result[1] !== null && in_array(strtolower($result[1]), $union, true),
					];
				}
			}

			if ($found !== []) {
				$docs[] = [$token, $trivia, $tokens, $found];
			}
		}

		foreach ($scope->find(NameNode::class) as $name) {
			$item = $name->parent;
			if ($item instanceof UseItemNode) {
				$found = $item->symbolKind === SymbolKind::ClassLike ? $find($item->fullName) : null;
				if ($found !== null) {
					$imports[] = [$item, $item->fullName, $found[0], $found[1], $found[2] ?? null];
				}

			} elseif ($name->symbolKind === SymbolKind::ClassLike && $name->isReference()) {
				$class = $resolver->resolveClass($name);
				$list = self::findList($name)[0] ?? null;
				if ($list !== null) {
					$lists[spl_object_id($list)][strtolower($class)] = true;
				}

				$found = $find($class);
				$refusal = $found === null || $found[1] === null ? null : self::findRefusal($name, $found[1], $types);
				if ($found === null) {
					continue;
				} elseif (($keeps !== null && $keeps($name)) || self::keepsAlive($name, $found[1], $resolver)) {
					$kept[strtolower($class)] = true;
				} else {
					$references[] = [$name, $found[0], $found[1], $found[2] ?? null, $refusal];
					if ($refusal !== null) {
						$kept[strtolower($class)] = true; // the reference left as it is needs its import
					}
				}
			}
		}

		// an annotation read as an attribute resolves through the import until it is one
		$kept += $annotations?->findAnnotatedClasses($scope, $context) ?? [];
		foreach ($imports as [$item, $class, $message, $new, $because]) {
			if (isset($kept[strtolower($class)])) {
				continue; // the short name below goes on naming the class the import brings
			} elseif (self::report($context, $item->name, $message, $new, $because) && $new !== null) {
				$local = self::replaceImport($item, $new, $context);
				if ($local !== null) {
					self::shortenFullyQualified($scope, $new, $local);
				}
			}
		}

		foreach ($references as [$name, $message, $new, $because, $refusal]) {
			$inList = self::findList($name);
			$list = $inList[0] ?? null;
			$named = $list === null || $new === null ? null : $lists[spl_object_id($list)] ?? [];
			if ($refusal !== null) {
				$context->report($name, $message . $refusal . '.', fixable: false);

			} elseif (!self::report($context, $name, $message, $new, $because) || $new === null) {
				continue;

			} elseif ($inList !== null && isset($named[strtolower($new)])) {
				// the list names the class already; what stood behind the last item stays behind the one before it
				[$list, $item] = $inList;
				$items = $list->getItems();
				if (end($items) === $item && count($items) > 1) {
					$items[count($items) - 2]->getLastToken()?->setTrailingTrivia($name->getLastToken()->trailingTrivia);
				}

				$list->removeItem($item);

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
	 * @return list<array{Token, Trivia, list<array{string, int, int}>, list<array{int, string, ?array{string, MemberKind}, list<string>}>}>
	 *   the token holding the doc comment, the comment, its tokens, and per name the index of its token, the class, the member it
	 *   names and the lowercased classes beside it in a union type
	 */
	private static function findDocReferences(FileNode|NamespaceNode $scope, RuleContext $context): array
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$result = [];
		foreach ($scope->getTokens() as $token) {
			foreach ($token->getDocComments() as $trivia) {
				$tokens = $phpDoc->getTokens($trivia);
				$names = [];
				$resolve = fn(int $index) => $resolver->resolveClass(NameNode::fromText($tokens[$index][0]), $token);
				foreach (self::findDocNames($phpDoc->parse($trivia), $tokens) as $index => $union) {
					$names[] = [
						$index,
						$resolve($index),
						self::findDocMember($tokens, $index),
						array_map(fn(int $index) => strtolower($resolve($index)), $union),
					];
				}

				if ($names !== []) {
					$result[] = [$token, $trivia, $tokens, $names];
				}
			}
		}

		return $result;
	}


	/**
	 * The indexes of the tokens of a doc comment that name a class: in a type, in a class constant, as the target of
	 * a tag the parser knows no type for, `@see Foo::bar()`, and of every inline tag, `{@link Foo}`; each with the
	 * indexes of the other names of the union type it stands in.
	 * @param  list<array{string, int, int}>  $tokens
	 * @return array<int, list<int>>
	 */
	private static function findDocNames(PhpDocNode $tree, array $tokens): array
	{
		$type = fn(int $index) => $tokens[$index][Lexer::TYPE_OFFSET] ?? null;
		// the target of a tag stands after it on the same line
		$target = fn(int $tag) => $type($tag + 1) === Lexer::TOKEN_HORIZONTAL_WS ? $tag + 2 : -1;
		$indexes = $unions = [];
		$walk = function (PhpDocAstNode $node) use (&$walk, &$indexes, &$unions, $target): void {
			$index = $node->getAttribute(Attribute::START_INDEX);
			if ($node instanceof IdentifierTypeNode || ($node instanceof ConstFetchNode && $node->className !== '')) {
				$indexes[] = $index;
			} elseif ($node instanceof PhpDocTagNode && $node->value instanceof GenericTagValueNode) {
				$indexes[] = $target($index);
			} elseif ($node instanceof PhpDocUnionTypeNode) {
				$names = array_map(
					fn(IdentifierTypeNode $type) => $type->getAttribute(Attribute::START_INDEX),
					array_filter($node->types, fn($type) => $type instanceof IdentifierTypeNode),
				);
				foreach ($names as $name) {
					$unions[$name] = array_values(array_diff($names, [$name]));
				}
			}

			foreach (get_object_vars($node) as $value) {
				foreach (is_array($value) ? $value : [$value] as $child) {
					// the key of a shape and of a Doctrine argument or array item is a name, not a type
					if (
						$child instanceof PhpDocAstNode
						&& !(($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) && $child === $node->keyName)
						&& !(($node instanceof DoctrineArgument || $node instanceof DoctrineArrayItem)
							&& $child === $node->key
							&& $child instanceof IdentifierTypeNode)
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

		$isName = fn(int $index) => $type($index) === Lexer::TOKEN_IDENTIFIER
			&& preg_match('~^' . self::ClassPattern . '$~D', $tokens[$index][Lexer::VALUE_OFFSET]);
		$result = [];
		foreach (array_filter(array_unique($indexes), $isName) as $index) {
			$result[$index] = array_values(array_filter($unions[$index] ?? [], $isName));
		}

		return $result;
	}


	/**
	 * The member the name of a class in a doc comment is followed by, `Foo::BAR` a constant, `Foo::bar()` a method.
	 * @param  list<array{string, int, int}>  $tokens
	 * @return ?array{string, MemberKind}
	 */
	private static function findDocMember(array $tokens, int $index): ?array
	{
		$type = fn(int $offset) => $tokens[$index + $offset][Lexer::TYPE_OFFSET] ?? null;
		return $type(1) === Lexer::TOKEN_DOUBLE_COLON && $type(2) === Lexer::TOKEN_IDENTIFIER
			? [
				$tokens[$index + 2][Lexer::VALUE_OFFSET],
				$type(3) === Lexer::TOKEN_OPEN_PARENTHESES ? MemberKind::Method : MemberKind::Constant,
			]
			: null;
	}


	/**
	 * Reports every name of a doc comment, and writes the class that replaces it where the report allows, token by
	 * token, so that the rest of the comment stays as it is written.
	 * @param  list<array{string, int, int}>  $tokens
	 * @param  list<array{int, string, ?string, ?string, bool}>  $found  per name the index of its token, the message, the class
	 *   written instead, why writing it is risky and whether the union type it stands in names that class already
	 */
	private static function replaceInDocComment(Token $token, Trivia $trivia, array $tokens, array $found, RuleContext $context): void
	{
		$changed = false;
		$unfixable = [];
		foreach ($found as [$index, $message, $new, $because, $named]) {
			if ($new === null || $token->parent === null) {
				$unfixable[] = $message; // reported once the comment is written, nothing changing after them
			} elseif (!self::report($context, $token, $message, $new, $because, $trivia)) {
				continue;
			} elseif ($named) {
				foreach (self::findDocUnionSpan($tokens, $index) as $i) {
					$tokens[$i][Lexer::VALUE_OFFSET] = '';
				}

				$changed = true;

			} else {
				$name = $tokens[$index][Lexer::VALUE_OFFSET];
				$tokens[$index][Lexer::VALUE_OFFSET] = str_starts_with($name, '\\')
					? '\\' . $new
					: CodeWriter::writeClass($new, $token->parent, $context);
				$changed = true;
			}
		}

		if ($changed) {
			$token->replaceTrivia($trivia, $trivia->withText(implode('', array_column($tokens, Lexer::VALUE_OFFSET))));
		}

		foreach ($unfixable as $message) {
			$context->report($token, $message . '.', trivia: $trivia, fixable: false);
		}
	}


	/** Reports the name, which the class written instead fixes, risky where there is a reason it may change what the code does. */
	private static function report(
		RuleContext $context,
		Node|Token $at,
		string $message,
		?string $new,
		?string $because,
		?Trivia $trivia = null,
	): bool
	{
		return $context->report(
			$at,
			$message . '.',
			trivia: $trivia,
			risk: $because === null ? null : Risk::BehaviorChanges,
			fixable: $new !== null,
			because: $because,
		);
	}


	/**
	 * The tokens that take the name out of the union type of a doc comment: the name and the `|` that joins it to its
	 * neighbour, with the whitespace between them.
	 * @param  array<int, array<int, int|string>>  $tokens
	 * @return list<int>
	 */
	private static function findDocUnionSpan(array $tokens, int $index): array
	{
		$isSpace = fn(int $i) => ($tokens[$i][Lexer::TYPE_OFFSET] ?? null) === Lexer::TOKEN_HORIZONTAL_WS;
		$before = $index - 1;
		while ($isSpace($before)) {
			$before--;
		}

		if (($tokens[$before][Lexer::TYPE_OFFSET] ?? null) === Lexer::TOKEN_UNION) {
			return range($before, $index);
		}

		// the first of the union: the `|` after it goes, and the whitespace around it
		$after = $index + 1;
		while ($isSpace($after)) {
			$after++;
		}

		$after++;
		while ($isSpace($after)) {
			$after++;
		}

		return range($index, $after - 1);
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


	/**
	 * The list a name goes from where the list names its replacement already, what a class implements or an interface
	 * extends, or the types of a union, with the item holding the name.
	 * @return ?array{SeparatedNodeList<covariant Node>, Node}
	 */
	private static function findList(NameNode $name): ?array
	{
		$inheritance = self::findInheritance($name);
		$type = $name->parent;
		return match (true) {
			$inheritance !== null => [$inheritance, $name],
			$type instanceof NamedTypeNode
			&& $type->parent instanceof SeparatedNodeList
			&& $type->parent->parent instanceof UnionTypeNode => [$type->parent, $type],
			default => null,
		};
	}


	/**
	 * Whether the name keeps the old class alive rather than uses it: the class `class_alias()` declares and the one
	 * `class_exists()` and its kin ask for, and the name the replacement itself extends or implements.
	 */
	private static function keepsAlive(NameNode $name, ?string $new, NameResolver $resolver): bool
	{
		$fetch = $name->parent;
		$call = $fetch?->parent?->parent?->parent?->parent; // the argument, the list, the parentheses, the call
		if (
			$fetch instanceof ClassConstantFetchNode
			&& $fetch->class === $name
			&& $fetch->plainName !== null
			&& strcasecmp($fetch->plainName, 'class') === 0
			&& $call instanceof FunctionCallNode
			&& $resolver->findGlobalFunction(
				$call,
				['class_alias', 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists'],
			) !== null
		) {
			return true;
		}

		$parent = $name->parent;
		$declaration = self::findInheritance($name)->parent
			?? ($parent instanceof ClassNode && $parent->extends === $name ? $parent : null);
		return $new !== null
			&& $declaration instanceof ClassLikeNode
			&& $declaration->name !== null
			&& strcasecmp($resolver->resolveClass(NameNode::fromText($declaration->name->text), $declaration), $new) === 0;
	}


	/** Why the class cannot stand where the name does, as a clause of the message; null where it can or the types cannot tell. */
	private static function findRefusal(NameNode $name, string $new, ?Types $types): ?string
	{
		$isInterface = $types?->isInterface($new);
		return match (true) {
			$isInterface === Tristate::No && self::findInheritance($name) !== null => ", but `$new` is a class, which is not implemented",
			$isInterface === Tristate::Yes
			&& $name->parent instanceof ClassNode
			&& $name->parent->extends === $name => ", but `$new` is an interface, which a class does not extend",
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
		$short = QualifiedNames::stripNamespace($new);
		if (
			str_starts_with(strtolower($new), strtolower($prefix))
			&& ($item->alias !== null
				|| strcasecmp($item->name->shortName, $short) === 0
				|| $context->getAnalysis(NameResolver::class)->isAliasFree($short, SymbolKind::ClassLike, $item))
		) {
			$item->name->text = substr($new, strlen($prefix));
			return $item->alias->text ?? $short;
		}

		$item->remove();

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
}
