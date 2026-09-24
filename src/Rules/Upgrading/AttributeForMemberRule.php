<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Parameter, Types};
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Tristate, Values};
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token, Trivia};
use PhpSyntax\Nodes\{ArgumentNode, AttributeGroupNode, AttributeNode, ExpressionNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Expression\{MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode, VariableNode};
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, ReturnNode};
use function count, in_array;


/**
 * A tool for a member a class declares for a library to read, which the library reads from an attribute of the class
 * now: the project, or a library it stands on, maps the member of an ancestor to the attribute written instead, and
 * a class declaring the member gets the attribute and loses the declaration with its doc comment, a value spread over
 * lines indented as the attribute is. `Acme\Console\Command::$defaultName:
 * Acme\Console\AsCommand(name: $value)` writes the default of the property as the argument, `'Acme\Orm\Model::$timestamps
 * = false': Acme\Orm\WithoutTimestamps` stands for that default only, `Acme\Orm\Model::getRouteKey(): Acme\Orm\RouteKey($value)`
 * for a method whose body returns a constant expression, and a key that is an interface, `Acme\Bus\Handler:
 * Acme\Bus\AsHandler`, for the interface a class names in its list, which the list then loses. The entries whose
 * attribute is of one class give it their arguments together, and an attribute of that class the class carries
 * already gets the named ones it lacks.
 *
 * Reported and left as it is: a class that reads the member itself, an abstract class, whose attribute the library
 * may not read, a property declared together with others or without a value, a method whose body does more than
 * return a constant expression, an interface the class inherits from its parent (with the types), an argument the
 * attribute on the class gives a value of its own, and an attribute whose constructor requires an argument the members
 * give no value of (with the types). Without the types only a parent or an interface the class names itself is known.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	decisions: ['upgrading.libraries.packages', 'upgrading.libraries.attributeForMember'],
	analyses: [Types::class, NameResolver::class],
)]
final class AttributeForMemberRule extends NodeRule
{
	public const Map = AttributeForMemberEntry::Path;

	/** @var list<AttributeForMemberEntry> */
	private array $entries = [];


	public function configure(Values $values): void
	{
		$this->entries = AttributeForMemberEntry::fromValues($values);
	}


	public function getVisitedNodes(): array
	{
		return [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode || $this->entries === []) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$types = $context->findAnalysis(Types::class);
		$class = (string) $resolver->getDeclaredName($node);
		$named = []; // the ancestors the class names itself, lowercased
		foreach ([$node->extends, ...($node->implements?->getItems() ?? [])] as $name) {
			if ($name instanceof NameNode) {
				$named[strtolower($resolver->resolveClass($name))] = $name;
			}
		}

		$found = $done = [];
		foreach ($this->entries as $entry) {
			$direct = $named[strtolower($entry->class)] ?? null;
			if (
				strcasecmp($class, $entry->class) === 0
				|| ($direct === null && $types?->isSubtype($class, $entry->class) !== Tristate::Yes)
			) {
				continue;
			}

			$member = $this->findMember($node, $entry, $direct);
			if ($member === null || isset($done[spl_object_id($member[0])])) {
				continue;
			}

			$done[spl_object_id($member[0])] = true;
			$found[] = [$entry, ...$member];
		}

		$this->write($node, $found, $context);
	}


	/**
	 * The declaration the entry stands for in the class, what its value is, and why it is not to be written; null for a
	 * class that does not declare it, or declares a property with another default than the one of the key.
	 * @return ?array{Node, ?ExpressionNode, ?string}  the declaration, the value, the refusal as a clause of the message
	 */
	private function findMember(ClassNode $class, AttributeForMemberEntry $entry, ?NameNode $direct): ?array
	{
		if ($entry->kind === AttributeForMemberKind::Interface) {
			return match (true) {
				$direct === null => [$class->name, null, ', but the class inherits it, and the library may not read the attribute of a child from its parent'],
				$direct === $class->extends => null,
				default => [$direct, null, null],
			};
		}

		foreach ($class->members as $member) {
			if ($entry->kind === AttributeForMemberKind::Property && $member instanceof PropertyNode) {
				$item = array_find($member->items->getItems(), fn($item) => $item->plainName === $entry->name);
				if ($item === null) {
					continue;
				}

				$default = $item->default;
				if ($entry->literal !== null && !($default?->hasValue() && $default->toValue() === $entry->literal[0])) {
					return null;
				}

				return [$member, $default, match (true) {
					count($member->items->getItems()) > 1 => ', but it is declared together with other properties',
					$default === null => ', but it has no value to write',
					default => null,
				}];

			} elseif (
				$entry->kind === AttributeForMemberKind::Method
				&& $member instanceof MethodNode
				&& $member->name->equals($entry->name)
			) {
				$statements = $member->body?->statements->getItems() ?? [];
				$return = count($statements) === 1 && $statements[0] instanceof ReturnNode ? $statements[0]->expression : null;
				return [
					$member,
					$return,
					$return !== null && $return->isConstantExpression() ? null : ', but its body does more than return a constant expression',
				];
			}
		}

		return null;
	}


	/**
	 * Reports every member found and writes the attributes of those the reports allow, their arguments joined by class.
	 * @param  list<array{AttributeForMemberEntry, Node, ?ExpressionNode, ?string}>  $found
	 */
	private function write(ClassNode $class, array $found, RuleContext $context): void
	{
		$planned = $reports = [];
		foreach ($found as [$entry, $member, $value, $refusal]) {
			$attribute = $entry->attribute;
			$refusal ??= match (true) {
				$class->modifiers->abstract => ', but the class is abstract, and the library may not read the attribute of a child from it',
				$entry->kind === AttributeForMemberKind::Interface => null,
				self::readsMember($class, $entry->kind, $entry->name, $context) => ', but the class reads it itself',
				default => null,
			};
			$described = match ($entry->kind) {
				AttributeForMemberKind::Property => "Property `$entry->class::\$$entry->name`",
				AttributeForMemberKind::Method => "Method `$entry->class::$entry->name()`",
				AttributeForMemberKind::Interface => "Interface `$entry->class`",
			};
			$at = match (true) {
				$member instanceof MethodNode => $member->name,
				$member instanceof PropertyNode => $member->items->getItems()[0],
				default => $member,
			};
			$arguments = $refusal === null ? $this->writeArguments($entry, $value, self::findIndentation($value ?? $member, $member), $class->getFirstToken()->getIndentation()) : [];
			$refusal ??= $this->findConflict($class, $attribute, $arguments, $planned, $context);
			if ($refusal === null) {
				$planned[strtolower($attribute)] = [$attribute, [...($planned[strtolower($attribute)][1] ?? []), ...$arguments]];
			}

			$reports[] = [$at, "$described is replaced by the attribute `#[$attribute]`", $refusal, $member, $attribute, $arguments];
		}

		// the members of one attribute together must give every argument it requires
		$missing = [];
		foreach ($planned as $key => [$attribute, $arguments]) {
			$existing = self::findAttribute($class, $attribute, $context);
			$missing[$key] = self::findMissingArgument($attribute, [...($existing === null ? [] : self::listArguments($existing)), ...$arguments], $context->findAnalysis(Types::class));
		}

		$attributes = $removed = [];
		foreach ($reports as [$at, $message, $refusal, $member, $attribute, $arguments]) {
			$refusal ??= $missing[strtolower($attribute)] ?? null;
			if ($refusal !== null) {
				$context->report($at, $message . $refusal . '.', fixable: false);
			} elseif ($context->report($at, $message . '.')) {
				$attributes[strtolower($attribute)] = [$attribute, [...($attributes[strtolower($attribute)][1] ?? []), ...$arguments]];
				$removed[] = $member;
			}
		}

		if ($removed === []) {
			return;
		}

		$codes = [];
		foreach ($attributes as [$attribute, $arguments]) {
			$existing = self::findAttribute($class, $attribute, $context);
			if ($existing !== null) {
				$existing->replaceWith((new Builder)->fragment(AttributeGroupNode::class, '#[' . self::writeAttribute($existing->name->text, [...self::listArguments($existing), ...$arguments]) . ']')->items->getItems()[0]->withoutEdgeTrivia());
			} else {
				$codes[] = self::writeAttribute(CodeWriter::writeClass($attribute, $class, $context), $arguments);
			}
		}

		foreach ($removed as $member) {
			if ($member instanceof NameNode) {
				self::removeInterface($class, $member);
			} else {
				self::removeDocComment($member);
				$member->remove(mergeBlankLines: true);
			}
		}

		CodeWriter::addAttributes($class, $codes, $context);
	}


	/**
	 * The arguments of the attribute of the entry, `$value` standing for the value of the member; the lines a value
	 * spreads over move from the indentation of the member to the one of the attribute.
	 * @return list<array{?string, string}>  the name of each, null for a positional one, and the argument as written
	 */
	private function writeArguments(AttributeForMemberEntry $entry, ?ExpressionNode $value, string $from, string $to): array
	{
		if ($entry->arguments === '') {
			return [];
		}

		$group = (new Builder)->fragment(AttributeGroupNode::class, "#[$entry->attribute$entry->arguments]");
		foreach ($group->find(VariableNode::class, fn(VariableNode $variable) => $variable->plainName === 'value') as $variable) {
			if ($value !== null) {
				$variable->replaceWithExpression($value->withoutEdgeTrivia());
			}
		}

		return array_map(
			fn(array $argument) => [$argument[0], str_replace("\n$from", "\n$to", $argument[1])],
			self::listArguments($group->items->getItems()[0]),
		);
	}


	/** The indentation of the line the value of the member begins on: the return of a method, or the member itself. */
	private static function findIndentation(Node $value, Node $member): string
	{
		$line = $value->parent instanceof ReturnNode ? $value->parent : $member;
		return $line->getFirstToken()?->getIndentation() ?? '';
	}


	/**
	 * Why the arguments cannot join the attribute: one of them is positional where the attribute has arguments already,
	 * or names one the attribute gives another value.
	 * @param  list<array{?string, string}>  $arguments
	 * @param  array<string, array{string, list<array{?string, string}>}>  $pending  the attributes written so far, by the lowercased class
	 */
	private function findConflict(ClassNode $class, string $attribute, array $arguments, array $pending, RuleContext $context): ?string
	{
		$existing = self::findAttribute($class, $attribute, $context);
		$present = [...($existing === null ? [] : self::listArguments($existing)), ...($pending[strtolower($attribute)][1] ?? [])];
		if ($present === [] && ($existing === null || $existing->arguments === null) && !isset($pending[strtolower($attribute)])) {
			return null;
		}

		$given = [];
		foreach ($present as [$name, $code]) {
			$given[strtolower($name ?? '')] = $code;
		}

		foreach ($arguments as [$name, $code]) {
			if ($name === null) {
				return ', but the attribute has arguments already, and a positional one cannot join them';
			} elseif (isset($given[strtolower($name)]) && $given[strtolower($name)] !== $code) {
				return ", but the attribute gives `$name` another value";
			}
		}

		return null;
	}


	/**
	 * Why the attribute cannot be written with the arguments: a parameter of its constructor they leave out has no
	 * default, which PHP refuses when the library reads the attribute. Null without the types.
	 * @param  list<array{?string, string}>  $arguments
	 */
	private static function findMissingArgument(string $class, array $arguments, ?Types $types): ?string
	{
		$parameters = $types?->findMethodParameters($class, '__construct') ?? [];
		$named = array_values(array_filter(array_column($arguments, 0), fn(?string $name) => $name !== null));
		$omitted = Parameter::findOmitted($parameters, count($arguments) - count($named), $named);
		return $omitted === null ? null : ", but the attribute requires `\$$omitted->name`, which the class gives no value of";
	}


	/**
	 * The attribute of the class the class carries, whichever way its name is written.
	 */
	private static function findAttribute(ClassNode $class, string $attribute, RuleContext $context): ?AttributeNode
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($class->attributes->getItems() as $group) {
			foreach ($group->items->getItems() as $node) {
				if (strcasecmp($resolver->resolveClass($node->name), $attribute) === 0) {
					return $node;
				}
			}
		}

		return null;
	}


	/** @return list<array{?string, string}>  the name of each argument, null for a positional one, and the argument as written */
	private static function listArguments(AttributeNode $attribute): array
	{
		$arguments = [];
		foreach ($attribute->arguments?->items->getItems() ?? [] as $argument) {
			if ($argument instanceof ArgumentNode) {
				$arguments[] = [$argument->name?->text, trim($argument->text)];
			}
		}

		return $arguments;
	}


	/**
	 * The attribute as code, its positional arguments first and a named one written once.
	 * @param  list<array{?string, string}>  $arguments
	 */
	private static function writeAttribute(string $class, array $arguments): string
	{
		$positional = $named = [];
		foreach ($arguments as [$name, $code]) {
			if ($name === null) {
				$positional[] = $code;
			} else {
				$named[strtolower($name)] ??= $code;
			}
		}

		$all = [...$positional, ...array_values($named)];
		return $class . ($all === [] ? '' : '(' . implode(', ', $all) . ')');
	}


	/** Whether the class reads the member of that name itself, through `$this`, `self::`, `static::` or its name. */
	private static function readsMember(ClassNode $class, AttributeForMemberKind $kind, string $name, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$own = strtolower((string) $resolver->getDeclaredName($class));
		$isOwn = fn(Node $reference) => $reference instanceof NameNode
			&& (in_array(strtolower($reference->text), ['self', 'static'], true) || strtolower($resolver->resolveClass($reference)) === $own);
		foreach ($class->members as $member) {
			foreach ($member->find(Node::class) as $node) {
				$read = match (true) {
					$kind === AttributeForMemberKind::Property && $node instanceof PropertyFetchNode => $node->object instanceof VariableNode && $node->object->isThis()
						&& $node->name instanceof IdentifierNode && $node->name->text === $name,
					$kind === AttributeForMemberKind::Property && $node instanceof StaticPropertyFetchNode => $node->plainName === $name && $isOwn($node->class),
					$kind === AttributeForMemberKind::Method && $node instanceof MethodCallNode => $node->object instanceof VariableNode && $node->object->isThis()
						&& $node->name instanceof IdentifierNode && $node->name->equals($name),
					$kind === AttributeForMemberKind::Method && $node instanceof StaticMethodCallNode => $node->name instanceof IdentifierNode
						&& $node->name->equals($name) && $isOwn($node->class),
					default => false,
				};
				if ($read) {
					return true;
				}
			}
		}

		return false;
	}


	/** Takes the interface out of the list of the class, and the list with its keyword where it is left empty. */
	private static function removeInterface(ClassNode $class, NameNode $interface): void
	{
		$list = $class->implements;
		if ($list === null) {
			return;

		} elseif (count($list->getItems()) > 1) {
			$items = $list->getItems();
			if (end($items) === $interface) {
				$items[count($items) - 2]->getLastToken()->setTrailingTrivia($interface->getLastToken()->trailingTrivia);
			}

			$list->removeItem($interface);
			return;
		}

		// what stood behind the list, the line ending before the brace among it, goes behind what stood before the keyword
		$before = ($class->extends ?? $class->name)->getLastToken();
		$before->setTrailingTrivia($interface->getLastToken()->trailingTrivia);
		$class->implements = null;
		$class->implementsKeyword = null;
	}


	/** Drops the doc comment of the member, which describes what goes; a comment standing apart from it stays. */
	private static function removeDocComment(Node $member): void
	{
		$docComment = $member->getDocComment();
		$trivia = $member->getFirstToken()->leadingTrivia ?? [];
		$index = $docComment === null ? false : array_search($docComment, $trivia, strict: true);
		if ($index === false) {
			return;
		}

		$after = array_slice($trivia, $index + 1);
		if (
			!array_any($after, fn(Trivia $trivia) => $trivia->isComment())
			&& count(array_filter($after, fn(Trivia $trivia) => $trivia->isLineEnding())) <= 1
		) {
			$member->removeTrivia($docComment);
		}
	}
}
