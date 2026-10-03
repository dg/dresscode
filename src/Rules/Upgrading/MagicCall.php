<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{MemberAccess, MemberKind, Types};
use PhpSyntax\Builder;
use PhpSyntax\Nodes\{ArgumentListNode, ExpressionNode, IdentifierNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, PropertyFetchNode};
use PhpSyntax\Nodes\Scalar\StringNode;


/**
 * The call of a magic method PHP makes for a property no class declares or for an offset, as a key of a map of members
 * is matched against it: a read of `$object->name` is `__get('name')`, `$object[] = $value` is `offsetSet(null, $value)`.
 * @internal
 */
final readonly class MagicCall
{
	/** the magic method PHP calls for a property, and for an offset, by what is done with it */
	private const Methods = [
		PropertyFetchNode::class => ['get' => '__get', 'set' => '__set', 'isset' => '__isset', 'unset' => '__unset'],
		ArrayAccessNode::class => ['get' => 'offsetGet', 'set' => 'offsetSet', 'isset' => 'offsetExists', 'unset' => 'offsetUnset'],
	];


	private function __construct(
		public MemberAccess $access,
		/** the name or the key, and the values */
		public ArgumentListNode $arguments,
		private bool $appends,
	) {
	}


	/**
	 * The call PHP makes for the property or the offset used the given way; null for a property a class declares,
	 * one whose name is an expression and for what is no object.
	 * @param  'get'|'set'|'isset'|'unset'  $use
	 * @param  list<ExpressionNode>  $values  what the magic method gets besides the name or the key, detached
	 */
	public static function find(PropertyFetchNode|ArrayAccessNode $node, string $use, array $values, Types $types): ?self
	{
		if ($node instanceof PropertyFetchNode) {
			$access = $node->name instanceof IdentifierNode ? $types->findMemberAccess($node) : null;
			if ($access === null || $access->declared) {
				return null;
			}

			$classes = $access->classes;
			$key = StringNode::fromValue($access->name);

		} else {
			$classes = $types->findClasses($node->expression);
			$key = $node->index;
		}

		return $classes === [] ? null : new self(
			new MemberAccess(MemberKind::Method, self::getMethod($node, $use), $classes, declared: true),
			(new Builder)->arguments([$key, ...$values]),
			$node instanceof ArrayAccessNode && $node->index === null,
		);
	}


	/**
	 * The name of the magic method PHP calls for the property or the offset used the given way, which a rule looks
	 * the keys up by before it asks the types.
	 * @param  'get'|'set'|'isset'|'unset'  $use
	 */
	public static function getMethod(PropertyFetchNode|ArrayAccessNode $node, string $use): string
	{
		return self::Methods[$node::class][$use];
	}


	/** The arguments in the words of the key; null where the call is not of the key. */
	public function bind(MemberPattern $pattern, Types $types): ?ArgumentBindings
	{
		// $object[] = $value passes null as the key, which only a key saying null means
		$key = $pattern->arguments?->items[0] ?? null;
		return !$this->appends || ($key?->hasLiteral && $key->literal === null)
			? $pattern->bind($this->access, $this->arguments, null, $types)
			: null;
	}
}
