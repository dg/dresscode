<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Access, MemberKind, Types};
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\CodeWriter;
use Nette\Schema\{Context, Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, ParseException, Parser, Token};
use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, AttributeGroupNode, AttributeNode, ExpressionNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, ClassConstantFetchNode, MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode, VariableNode};
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
	'dresscode/attribute-for-member',
	Stage::Structure,
	description: 'Writes the attribute of a class a library reads instead of a member the class declares',
	modifiesComments: true,
)]
final class AttributeForMemberRule extends NodeRule implements ConfigurableRule
{
	private const ClassPattern = '\\\\?(\w+(?:\\\\\w+)*)';

	/** @var list<array{string, string, string, ?array{mixed}, string, string}>  kind, class, member, literal default, attribute, its arguments */
	private array $entries = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::arrayOf(Expect::string(), Expect::string())
			->description('The member of an ancestor, `Class::$name`, `\'Class::$name = literal\'`, `Class::method()` or an interface → the attribute written instead, `$value` standing for the value of the member')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $key => $value) {
					try {
						if ($value !== MemberMaps::Keep) {
							self::parseEntry((string) $key, $value);
						}
					} catch (\InvalidArgumentException $e) {
						$context->addError($e->getMessage(), 'dresscode.memberMap');
					}
				}

				return $options;
			});
	}


	public function configure(array $options): void
	{
		$this->entries = [];
		foreach ($options as $key => $value) {
			if ($value !== MemberMaps::Keep) {
				$this->entries[] = self::parseEntry((string) $key, $value);
			}
		}

		// an entry for a default comes before one for any value of the same property
		usort($this->entries, fn(array $a, array $b) => ($b[3] !== null) <=> ($a[3] !== null));
	}


	/**
	 * @return array{string, string, string, ?array{mixed}, string, string}
	 * @throws \InvalidArgumentException
	 */
	private static function parseEntry(string $key, string $value): array
	{
		if (!preg_match('~^' . self::ClassPattern . '(\(.*\))?$~Ds', trim($value), $attribute)) {
			throw new \InvalidArgumentException("The attribute '$value' for $key is not written as Class or Class(arguments).");
		}

		$arguments = $attribute[2] ?? '';
		try {
			(new Parser)->parseFragment(AttributeGroupNode::class, "#[$attribute[1]$arguments]");
		} catch (ParseException $e) {
			throw new \InvalidArgumentException("The attribute '$value' for $key does not read as an attribute: {$e->getMessage()}", previous: $e);
		}

		$key = trim($key);
		return match (true) {
			(bool) preg_match('~^' . self::ClassPattern . '::\$(\w+)(?:\s*=\s*(.+))?$~Ds', $key, $m) => [
				'property',
				$m[1],
				$m[2],
				isset($m[3]) ? [self::readLiteral($m[3], $key)] : null,
				$attribute[1],
				$arguments,
			],
			(bool) preg_match('~^' . self::ClassPattern . '::(\w+)\(\)$~D', $key, $m) => ['method', $m[1], $m[2], null, $attribute[1], $arguments],
			(bool) preg_match('~^' . self::ClassPattern . '$~D', $key, $m) => ['interface', $m[1], '', null, $attribute[1], $arguments],
			default => throw new \InvalidArgumentException("The member '$key' is not written as Class::\$name, 'Class::\$name = literal', Class::method() or Interface."),
		};
	}


	/** @throws \InvalidArgumentException */
	private static function readLiteral(string $code, string $key): mixed
	{
		try {
			$expression = (new Parser)->parseExpression($code);
		} catch (ParseException) {
			$expression = null;
		}

		return $expression?->hasValue()
			? $expression->toValue()
			: throw new \InvalidArgumentException("The default in '$key' is no literal.");
	}


	public function getVisitedTypes(): array
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
		$class = ltrim($resolver->getNamespace($node) . '\\' . $node->name->text, '\\');
		$named = []; // the ancestors the class names itself, lowercased
		foreach ([$node->extends, ...($node->implements?->getItems() ?? [])] as $name) {
			if ($name instanceof NameNode) {
				$named[strtolower($resolver->resolveClass($name))] = $name;
			}
		}

		$found = $done = [];
		foreach ($this->entries as $entry) {
			$ancestor = $entry[1];
			$direct = $named[strtolower($ancestor)] ?? null;
			if (strcasecmp($class, $ancestor) === 0 || ($direct === null && !$types?->isSubtype($class, $ancestor))) {
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
	 * @param  array{string, string, string, ?array{mixed}, string, string}  $entry
	 * @return ?array{Node, ?ExpressionNode, ?string}  the declaration, the value, the refusal as a clause of the message
	 */
	private function findMember(ClassNode $class, array $entry, ?NameNode $direct): ?array
	{
		[$kind, , $name, $literal] = $entry;
		if ($kind === 'interface') {
			return match (true) {
				$direct === null => [$class->name, null, ', but the class inherits it, and the library may not read the attribute of a child from its parent'],
				$direct === $class->extends => null,
				default => [$direct, null, null],
			};
		}

		foreach ($class->members as $member) {
			if (
				$kind === 'property'
				&& $member instanceof PropertyNode
				&& array_any($member->items->getItems(), fn($item) => $item->plainName === $name)
			) {
				$item = array_find($member->items->getItems(), fn($item) => $item->plainName === $name);
				$default = $item?->default;
				if ($literal !== null && !($default?->hasValue() && $default->toValue() === $literal[0])) {
					return null;
				}

				return [$member, $default, match (true) {
					count($member->items->getItems()) > 1 => ', but it is declared together with other properties',
					$default === null => ', but it has no value to write',
					default => null,
				}];

			} elseif ($kind === 'method' && $member instanceof MethodNode && strcasecmp($member->name->text, $name) === 0) {
				$statements = $member->body?->statements->getItems() ?? [];
				$return = count($statements) === 1 && $statements[0] instanceof ReturnNode ? $statements[0]->expression : null;
				return [
					$member,
					$return,
					$return !== null && self::isConstant($return) ? null : ', but its body does more than return a constant expression',
				];
			}
		}

		return null;
	}


	/** Whether an attribute may take the expression as its argument, which takes constant expressions alone. */
	private static function isConstant(ExpressionNode $expression): bool
	{
		return $expression->hasValue()
			|| ($expression instanceof ClassConstantFetchNode && $expression->class instanceof NameNode)
			|| ($expression instanceof ArrayNode && array_all(
				$expression->items->getItems(),
				fn($item) => $item instanceof ArrayItemNode
					&& $item->ellipsis === null
					&& $item->value instanceof ExpressionNode
					&& self::isConstant($item->value)
					&& ($item->key === null || self::isConstant($item->key)),
			));
	}


	/**
	 * Reports every member found and writes the attributes of those the reports allow, their arguments joined by class.
	 * @param  list<array{array{string, string, string, ?array{mixed}, string, string}, Node, ?ExpressionNode, ?string}>  $found
	 */
	private function write(ClassNode $class, array $found, RuleContext $context): void
	{
		$planned = $reports = [];
		foreach ($found as [$entry, $member, $value, $refusal]) {
			[$kind, $ancestor, $name, , $attribute] = $entry;
			$refusal ??= match (true) {
				$class->modifiers->isAbstract() => ', but the class is abstract, and the library may not read the attribute of a child from it',
				$kind === 'interface' => null,
				self::readsMember($class, $kind, $name, $context) => ', but the class reads it itself',
				default => null,
			};
			$described = match ($kind) {
				'property' => "Property $ancestor::\$$name",
				'method' => "Method $ancestor::$name()",
				default => "Interface $ancestor",
			};
			$at = match (true) {
				$member instanceof MethodNode => $member->name,
				$member instanceof PropertyNode => $member->items->getItems()[0],
				default => $member,
			};
			$arguments = $refusal === null ? $this->writeArguments($entry, $value, self::findIndentation($value ?? $member, $member), $class->getFirstToken()?->getIndentation() ?? '') : [];
			$refusal ??= $this->findConflict($class, $attribute, $arguments, $planned, $context);
			if ($refusal === null) {
				$planned[strtolower($attribute)] = [$attribute, [...($planned[strtolower($attribute)][1] ?? []), ...$arguments]];
			}

			$reports[] = [$at, "$described is replaced by the attribute #[$attribute]", $refusal, $member, $attribute, $arguments];
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
				$context->report($at, $message . $refusal, fixable: false);
			} elseif ($context->report($at, $message)) {
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
				$existing->replaceWith((new Parser)->parseFragment(AttributeGroupNode::class, '#[' . self::writeAttribute($existing->name->text, [...self::listArguments($existing), ...$arguments]) . ']')->attributes->getItems()[0]->withoutEdgeTrivia());
			} else {
				$codes[] = self::writeAttribute(CodeWriter::spellClass($attribute, $class, $context), $arguments);
			}
		}

		foreach ($removed as $member) {
			if ($member instanceof NameNode) {
				self::removeInterface($class, $member);
			} else {
				self::removeDocComment($member);
				CodeWriter::removeBetweenGaps($member, $context->getStyle()->eol);
			}
		}

		CodeWriter::addAttributes($class, $class->attributes, $codes, $context);
	}


	/**
	 * The arguments of the attribute of the entry, `$value` standing for the value of the member; the lines a value
	 * spreads over move from the indentation of the member to the one of the attribute.
	 * @param  array{string, string, string, ?array{mixed}, string, string}  $entry
	 * @return list<array{?string, string}>  the name of each, null for a positional one, and the argument as written
	 */
	private function writeArguments(array $entry, ?ExpressionNode $value, string $from, string $to): array
	{
		if ($entry[5] === '') {
			return [];
		}

		$group = (new Parser)->parseFragment(AttributeGroupNode::class, "#[$entry[4]$entry[5]]");
		foreach ($group->find(VariableNode::class, fn(VariableNode $variable) => $variable->plainName === 'value') as $variable) {
			if ($value !== null) {
				$variable->replaceWithExpression($value->withoutEdgeTrivia());
			}
		}

		return array_map(
			fn(array $argument) => [$argument[0], str_replace("\n$from", "\n$to", $argument[1])],
			self::listArguments($group->attributes->getItems()[0]),
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
				return ", but the attribute gives $name another value";
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
		$parameters = $types?->findParameters(new Access(MemberKind::Constructor, '__construct', [$class], true)) ?? [];
		$positional = count(array_filter($arguments, fn(array $argument) => $argument[0] === null));
		$named = array_flip(array_map(fn(array $argument) => strtolower($argument[0] ?? ''), $arguments));
		foreach ($parameters as $position => $parameter) {
			if (!$parameter->optional && $position >= $positional && !isset($named[strtolower($parameter->name)])) {
				return ", but the attribute requires \$$parameter->name, which the class gives no value of";
			}
		}

		return null;
	}


	/**
	 * The attribute of the class the class carries, whichever way its name is written.
	 */
	private static function findAttribute(ClassNode $class, string $attribute, RuleContext $context): ?AttributeNode
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($class->attributes->getItems() as $group) {
			foreach ($group->attributes->getItems() as $node) {
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


	/** Whether the class reads the member of that name itself, through $this, self::, static:: or its name. */
	private static function readsMember(ClassNode $class, string $kind, string $name, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$own = strtolower(ltrim($resolver->getNamespace($class) . '\\' . $class->name->text, '\\'));
		$isOwn = fn(Node $reference) => $reference instanceof NameNode
			&& (in_array(strtolower($reference->text), ['self', 'static'], true) || strtolower($resolver->resolveClass($reference)) === $own);
		foreach ($class->members as $member) {
			foreach ($member->find(Node::class) as $node) {
				$read = match (true) {
					$kind === 'property' && $node instanceof PropertyFetchNode => $node->object instanceof VariableNode && $node->object->isThis()
						&& $node->name instanceof IdentifierNode && $node->name->text === $name,
					$kind === 'property' && $node instanceof StaticPropertyFetchNode => $node->plainName === $name && $isOwn($node->class),
					$kind === 'method' && $node instanceof MethodCallNode => $node->object instanceof VariableNode && $node->object->isThis()
						&& $node->name instanceof IdentifierNode && strcasecmp($node->name->text, $name) === 0,
					$kind === 'method' && $node instanceof StaticMethodCallNode => $node->name instanceof IdentifierNode
						&& strcasecmp($node->name->text, $name) === 0 && $isOwn($node->class),
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
				$items[count($items) - 2]->getLastToken()?->setTrailingTrivia($interface->getLastToken()->trailingTrivia ?? []);
			}

			$list->removeItem($interface);
			return;
		}

		// what stood behind the list, the line ending before the brace among it, goes behind what stood before the keyword
		$before = ($class->extends ?? $class->name)->getLastToken();
		$before?->setTrailingTrivia($interface->getLastToken()->trailingTrivia ?? []);
		$class->implements = null;
		$class->implementsKeyword = null;
	}


	/** Drops the doc comment of the member, which describes what goes; a comment standing apart from it stays. */
	private static function removeDocComment(Node $member): void
	{
		$first = $member->getFirstToken();
		$trivia = $first->leadingTrivia ?? [];
		for ($i = count($trivia) - 1; $i >= 0 && !$trivia[$i]->isComment(); $i--);
		$after = array_slice($trivia, $i + 1);
		if (
			$first !== null
			&& $i >= 0
			&& $trivia[$i]->isDocComment()
			&& count(array_filter($after, fn($trivia) => $trivia->isEndOfLine())) <= 1
		) {
			$first->setLeadingTrivia(array_slice($trivia, 0, $i));
		}
	}


	/** Whether the map has the interface, fully qualified, which is what a rule reading the deprecations asks to stay silent. */
	public function knows(string $class): bool
	{
		return array_any($this->entries, fn(array $entry) => $entry[0] === 'interface' && strcasecmp($entry[1], $class) === 0);
	}
}
