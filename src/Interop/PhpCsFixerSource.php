<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Interop;

use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, ParseException, Parser};
use PhpSyntax\Nodes\{ArgumentListNode, ArrayItemNode, ElseifNode, ElseNode, ExpressionNode, MatchArmNode, NameNode};
use PhpSyntax\Nodes\Expression\{
	ArrayNode, AssignmentNode, BinaryOpNode, MatchNode, MethodCallNode, NewNode, ParenthesizedNode, StaticMethodCallNode, TernaryNode,
	VariableNode,
};
use PhpSyntax\Nodes\Scalar\{MagicConstantNode, StringNode};
use PhpSyntax\Nodes\Statement\{IfNode, SwitchNode};
use function is_string;


/**
 * What a configuration of PHP CS Fixer says in its code without running it: what its finder takes and leaves out. The
 * finder is a chain of calls starting at `Finder::create()`, `new Finder` of PHP CS Fixer or of Symfony,
 * `$config->getFinder()` or a variable one of them was assigned to, and the arguments are read where they are literals,
 * `__DIR__`, `__FILE__` and what `.` joins of them, lists among them. What the reading cannot be sure of is told in
 * `$unread`: a call reading something else or of a method it does not know, a call under a condition, several finders, a
 * variable of a finder assigned again, and a class named `Finder` of another vendor.
 * @internal
 */
final readonly class PhpCsFixerSource
{
	/** the calls of a finder that narrow it, which a scope of DressCode writes */
	private const Narrowing = ['in', 'exclude', 'notPath', 'notName', 'append'];

	/** the calls of a finder that narrow it in a way no scope of DressCode writes */
	private const Unwritable = ['name', 'path', 'filter', 'depth', 'date', 'size', 'contains', 'notContains'];

	/** the calls of a finder that change nothing of what it finds */
	private const Harmless = ['files', 'sortByName', 'sortByType', 'ignoreUnreadableDirs', 'getIterator', 'hasResults', 'count'];

	/** the classes of a finder */
	private const FinderClasses = ['phpcsfixer\finder', 'symfony\component\finder\finder'];


	public function __construct(
		/** @var list<string>  the directories `in()` takes, absolute */
		public array $in = [],
		/** @var list<string>  the directories `exclude()` leaves out, relative to each of them */
		public array $exclude = [],
		/** @var list<string>  what `notPath()` leaves out: a regular expression, or a text a path holds */
		public array $notPath = [],
		/** @var list<string>  the globs of the names `notName()` leaves out */
		public array $notName = [],
		/** @var list<string>  the files `append()` adds, as written */
		public array $append = [],
		/** @var list<string>  the calls whose arguments were not read, or that narrow the finder in a way no scope writes */
		public array $unread = [],
	) {
	}


	/** What the code of the configuration file says; nothing for code that does not parse. */
	public static function fromCode(string $code, string $file): self
	{
		try {
			$tree = new Parser()->parse($code);
		} catch (ParseException) {
			return new self(unread: ['the configuration, which does not parse']);
		}

		$resolver = new NameResolver($tree);
		$found = array_fill_keys([...self::Narrowing, 'unread'], []);
		$finders = [];
		foreach ($tree->find(AssignmentNode::class) as $assignment) {
			if ($assignment->target instanceof VariableNode && ($name = $assignment->target->plainName) !== null) {
				if (isset($finders[$name])) {
					$found['unread'][] = "\$$name, which a finder was assigned to and is assigned again";
				} elseif (self::isFinder($assignment->expression, $finders, $resolver)) {
					$finders[$name] = true;
				}
			}
		}

		$created = 0;
		foreach ([...$tree->find(StaticMethodCallNode::class), ...$tree->find(NewNode::class)] as $node) {
			if ($node->class instanceof NameNode && $node->class->shortName === 'Finder') {
				if (!self::namesFinder($node->class, $resolver)) {
					$found['unread'][] = '`' . $resolver->resolveClass($node->class, $node) . '`, a class named Finder of another vendor';
				} elseif ($node instanceof NewNode || $node->plainName === 'create') {
					$created++;
				}
			}
		}

		if ($created > 1) {
			$found['unread'][] = "$created finders, whose scopes are taken together";
		}

		foreach ($tree->find(MethodCallNode::class) as $call) {
			$method = $call->plainName;
			if ($method === null || !self::isFinder($call->object, $finders, $resolver)) {
				continue;
			} elseif (self::isConditional($call)) {
				$found['unread'][] = "$method(" . self::stripParens((string) $call->arguments) . '), which a condition decides';
				continue;
			} elseif (in_array($method, self::Unwritable, true) || !in_array($method, [...self::Narrowing, ...self::Harmless], true)) {
				$found['unread'][] = "$method()";
				continue;
			} elseif (!in_array($method, self::Narrowing, true)) {
				continue;
			}

			$values = self::readArguments($call->arguments, $file);
			if ($values === null) {
				$found['unread'][] = "$method(" . self::stripParens((string) $call->arguments) . ')';
			} else {
				array_push($found[$method], ...$values);
			}
		}

		return new self(
			$found['in'],
			$found['exclude'],
			$found['notPath'],
			$found['notName'],
			$found['append'],
			$found['unread'],
		);
	}


	/** @param  array<string, true>  $finders  the variables a finder was assigned to */
	private static function isFinder(ExpressionNode $node, array $finders, NameResolver $resolver): bool
	{
		while (($node instanceof MethodCallNode && $node->plainName !== 'getFinder') || $node instanceof ParenthesizedNode) {
			$node = $node instanceof ParenthesizedNode ? $node->expression : $node->object;
		}

		return match (true) {
			$node instanceof MethodCallNode => true,
			$node instanceof StaticMethodCallNode => $node->plainName === 'create' && self::namesFinder($node->class, $resolver),
			$node instanceof NewNode => self::namesFinder($node->class, $resolver),
			$node instanceof VariableNode => isset($finders[(string) $node->plainName]),
			default => false,
		};
	}


	private static function namesFinder(mixed $class, NameResolver $resolver): bool
	{
		return $class instanceof NameNode && in_array(strtolower($resolver->resolveClass($class, $class)), self::FinderClasses, true);
	}


	/** Whether a branch, a condition or an arm decides whether the call is made. */
	private static function isConditional(Node $node): bool
	{
		for ($node = $node->parent; $node !== null; $node = $node->parent) {
			if (
				$node instanceof IfNode
				|| $node instanceof ElseifNode
				|| $node instanceof ElseNode
				|| $node instanceof SwitchNode
				|| $node instanceof TernaryNode
				|| $node instanceof MatchNode
				|| $node instanceof MatchArmNode
			) {
				return true;
			}
		}

		return false;
	}


	/**
	 * The strings the arguments are, a list unpacked; null where one is no literal.
	 * @return ?list<string>
	 */
	private static function readArguments(ArgumentListNode $arguments, string $file): ?array
	{
		$values = [];
		foreach ($arguments->getPlainValues() ?? [null] as $argument) {
			$value = $argument === null ? null : self::readValue($argument, $file);
			if ($value === null) {
				return null;
			}

			array_push($values, ...(array) $value);
		}

		return $values;
	}


	/** @return string|list<string>|null */
	private static function readValue(ExpressionNode $node, string $file): string|array|null
	{
		if ($node instanceof StringNode) {
			return $node->toValue();
		} elseif ($node instanceof MagicConstantNode) {
			return match (strtoupper($node->token->text)) {
				'__DIR__' => dirname($file),
				'__FILE__' => $file,
				default => null,
			};
		} elseif ($node instanceof BinaryOpNode && $node->operator->text === '.') {
			$left = self::readValue($node->left, $file);
			$right = self::readValue($node->right, $file);
			return is_string($left) && is_string($right) ? $left . $right : null;
		} elseif ($node instanceof ArrayNode) {
			$values = [];
			foreach ($node->items as $item) {
				$value = $item instanceof ArrayItemNode && $item->key === null && $item->value instanceof ExpressionNode
					? self::readValue($item->value, $file)
					: null;
				if (!is_string($value)) {
					return null;
				}

				$values[] = $value;
			}

			return $values;
		}

		return null;
	}


	private static function stripParens(string $arguments): string
	{
		return trim(substr(trim($arguments), 1, -1));
	}
}
