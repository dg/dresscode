<?php declare(strict_types=1);

namespace PhpSyntax;

use function count, strlen;


/**
 * Order and positions of the tokens of a file, built lazily and rebuilt after a mutation:
 * a change of text or trivia invalidates the positions, a structural change also the order.
 */
final class TokenIndex
{
	/** @var list<Token> */
	private array $tokens = [];

	/** @var array<int, int>  spl_object_id → index in $tokens */
	private array $indexes = [];

	/** @var list<int>  byte offset of the token text */
	private array $offsets = [];

	/** @var list<int> */
	private array $lines = [];

	/** @var list<int>  1-based, in UTF-8 characters */
	private array $columns = [];

	private bool $orderValid = false;
	private bool $positionsValid = false;


	public function __construct(
		private readonly Node $root,
	) {
	}


	public function invalidate(bool $structure): void
	{
		$this->positionsValid = false;
		if ($structure) {
			$this->orderValid = false;
		}
	}


	/** @return list<Token> */
	public function getTokens(): array
	{
		$this->ensureOrder();
		return $this->tokens;
	}


	public function getNext(Token $token): ?Token
	{
		return $this->tokens[$this->getIndex($token) + 1] ?? null;
	}


	public function getPrevious(Token $token): ?Token
	{
		$index = $this->getIndex($token);
		return $index > 0 ? $this->tokens[$index - 1] : null;
	}


	public function getIndex(Token $token): int
	{
		$this->ensureOrder();
		return $this->indexes[spl_object_id($token)]
			?? throw new \InvalidArgumentException('The token does not belong to the indexed tree.');
	}


	public function getOffset(Token $token): int
	{
		$this->ensurePositions();
		return $this->offsets[$this->getIndex($token)];
	}


	public function getLine(Token $token): int
	{
		$this->ensurePositions();
		return $this->lines[$this->getIndex($token)];
	}


	public function getColumn(Token $token): int
	{
		$this->ensurePositions();
		return $this->columns[$this->getIndex($token)];
	}


	/**
	 * Column with tabs expanded to the next multiple of the tab width, 1-based.
	 */
	public function getVisualColumn(Token $token, Style $style): int
	{
		$prefix = '';
		for ($i = $this->getIndex($token) - 1; $i >= 0; $i--) {
			$prefix = $this->tokens[$i] . $prefix;
			if (preg_match('~[\r\n]~', $prefix)) {
				break;
			}
		}

		foreach ($token->leadingTrivia as $trivia) {
			$prefix .= $trivia->text;
		}

		$prefix = preg_replace('~^.*[\r\n]~s', '', $prefix);
		$column = 0;
		foreach (preg_split('~(\t)~', $prefix, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
			$column = $piece === "\t"
				? intdiv($column, $style->tabWidth) * $style->tabWidth + $style->tabWidth
				: $column + self::countCharacters($piece);
		}

		return $column + 1;
	}


	private function ensureOrder(): void
	{
		if ($this->orderValid) {
			return;
		}

		$this->tokens = $this->indexes = [];
		$stack = [$this->root];
		while ($stack) {
			$node = array_pop($stack);
			if ($node instanceof Token) {
				$this->indexes[spl_object_id($node)] = count($this->tokens);
				$this->tokens[] = $node;
			} else {
				$children = $node->getChildren();
				for ($i = count($children) - 1; $i >= 0; $i--) {
					$stack[] = $children[$i];
				}
			}
		}

		$this->orderValid = true;
		$this->positionsValid = false;
	}


	private function ensurePositions(): void
	{
		$this->ensureOrder();
		if ($this->positionsValid) {
			return;
		}

		$this->offsets = $this->lines = $this->columns = [];
		$offset = 0;
		$line = 1;
		$column = 1;
		foreach ($this->tokens as $token) {
			foreach ($token->leadingTrivia as $trivia) {
				self::advance($trivia->text, $offset, $line, $column);
			}

			$this->offsets[] = $offset;
			$this->lines[] = $line;
			$this->columns[] = $column;
			self::advance($token->text, $offset, $line, $column);
			foreach ($token->trailingTrivia as $trivia) {
				self::advance($trivia->text, $offset, $line, $column);
			}
		}

		$this->positionsValid = true;
	}


	private static function advance(string $text, int &$offset, int &$line, int &$column): void
	{
		$offset += strlen($text);
		$newlines = preg_match_all('~\r\n|\r|\n~', $text, $m, PREG_OFFSET_CAPTURE);
		if ($newlines) {
			$line += $newlines;
			$last = $m[0][$newlines - 1];
			$column = 1 + self::countCharacters(substr($text, $last[1] + strlen($last[0])));
		} else {
			$column += self::countCharacters($text);
		}
	}


	private static function countCharacters(string $text): int
	{
		return strlen($text) - preg_match_all('~[\x80-\xBF]~', $text);
	}
}
