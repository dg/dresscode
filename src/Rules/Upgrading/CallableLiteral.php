<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use PhpSyntax\Node;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use function count, strlen;


/**
 * The literal naming the method in a callable written as a value, `[$object, 'name']` or `'Acme\Order::name'`, found
 * by the shape alone, so that a rule looks the name up before it asks the types whether the value is callable.
 * @internal
 */
final readonly class CallableLiteral
{
	private function __construct(
		public StringNode $literal,
		/** the name of the method as written */
		public string $method,
	) {
	}


	public static function find(Node $node): ?self
	{
		if ($node instanceof StringNode) {
			$parts = str_contains($node->token->text, '::') ? explode('::', $node->toValue(), 2) : [];
			return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ? new self($node, $parts[1]) : null;
		} elseif (!$node instanceof ArrayNode) {
			return null;
		}

		$items = $node->items->getItems();
		if (count($items) !== 2 || !self::isPlainItem($items[0]) || !self::isPlainItem($items[1])) {
			return null;
		}

		$name = $items[1]->value;
		return $name instanceof StringNode ? new self($name, $name->toValue()) : null;
	}


	/** @phpstan-assert-if-true ArrayItemNode $item */
	private static function isPlainItem(Node $item): bool
	{
		return $item instanceof ArrayItemNode && $item->key === null && $item->ellipsis === null && $item->ampersand === null;
	}


	/** Writes another name of the method into the literal, the class it may name kept as it is spelled. */
	public function rename(string $name): void
	{
		$text = $this->literal->token->text;
		$at = strlen($text) - 1 - strlen($this->method);
		if (substr($text, $at, -1) === $this->method) {
			$this->literal->token->setText(substr($text, 0, $at) . $name . substr($text, -1));
		} else {
			$value = $this->literal->toValue();
			$this->literal->setValue(substr($value, 0, strlen($value) - strlen($this->method)) . $name);
		}
	}
}
