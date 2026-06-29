<?php declare(strict_types=1);

namespace PhpSyntax\Nodes;

use PhpSyntax\Node;
use PhpSyntax\Token;


/**
 * Modifier keywords in source order (public, static, readonly, abstract, final, var, public(set)...); may be empty.
 */
final class ModifiersNode extends Node
{
	/**
	 * @param list<Token> $tokens
	 * @internal
	 */
	public function __construct(
		public array $tokens = [],
	) {
	}


	/** @return list<Token> */
	public function getTokens(): array
	{
		return $this->tokens;
	}


	public function isEmpty(): bool
	{
		return $this->tokens === [];
	}


	public function has(int $kind): bool
	{
		foreach ($this->tokens as $token) {
			if ($token->kind === $kind) {
				return true;
			}
		}

		return false;
	}


	public function append(Token $token): void
	{
		$this->adopt($token);
		$this->tokens[] = $token;
		$this->structureChanged();
	}


	public function remove(Token $token): void
	{
		$index = array_search($token, $this->tokens, strict: true);
		if ($index === false) {
			throw self::describeChildMismatch($token);
		}

		$this->release($token);
		$this->tokens = self::spliceList($this->tokens, $index, 1);
		$this->structureChanged();
	}


	public function getChildren(): array
	{
		return $this->tokens;
	}


	public function replaceChild(Node|Token $old, Node|Token $new): void
	{
		$index = $old instanceof Token ? array_search($old, $this->tokens, strict: true) : false;
		if ($index === false || !$new instanceof Token) {
			throw self::describeChildMismatch($old);
		}

		$this->adopt($new);
		$this->release($old);
		$this->tokens = self::spliceList($this->tokens, $index, 1, [$new]);
		$this->structureChanged();
	}
}
