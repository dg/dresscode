<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use DressCode\Config\Layer;
use DressCode\Domains\{Count, Flag, Map, Names, Shapes, Text, Words};
use function count, is_array, is_string;


/**
 * A decided value, normalized by its domain, with the layer that said it. Each getter belongs to one domain and
 * throws for a value of another, which is a mistake of the rule asking.
 */
final readonly class Value
{
	/** @internal made by a domain */
	public function __construct(
		public Domain $domain,
		/**
		 * normalized: a list of words, the first being the one written (Words), a shape (Shapes), a range
		 * `[min, max]` or a word (Count), a bool (Flag), a list of names (Names) or the entries by their names (Map),
		 * each entry a Value; null for `keep`
		 */
		public mixed $content,
		/** the value as the layer wrote it, for the export */
		public mixed $raw,
		/** the layer that said it */
		public ?Layer $origin = null,
		private bool $keep = false,
	) {
	}


	/** @internal */
	public static function keep(Domain $domain, ?Layer $origin = null): self
	{
		return new self($domain, null, 'keep', $origin, keep: true);
	}


	/** The value said by the layer, its entries included. */
	public function withOrigin(Layer $origin): self
	{
		$content = $this->holdsEntries()
			? array_map(fn(self $entry) => $entry->withOrigin($origin), $this->content)
			: $this->content;
		return new self($this->domain, $content, $this->raw, $origin, $this->keep);
	}


	/** The normalized content as plain data, `keep` as the word and every entry as its own data; the identity of the value. */
	public function toData(): mixed
	{
		return match (true) {
			$this->keep => 'keep',
			$this->holdsEntries() => array_map(fn(self $entry) => $entry->toData(), $this->content),
			default => $this->content,
		};
	}


	/** The data the way a configuration writes them: a word for a tolerance of it alone, a number for an exact count. */
	public function toWrittenData(): mixed
	{
		$data = $this->holdsEntries()
			? array_map(fn(self $entry) => $entry->toWrittenData(), $this->content)
			: $this->toData();
		$data = $this->domain instanceof Words && is_array($data) && array_is_list($data) && count($data) === 1 ? $data[0] : $data;
		return $this->domain instanceof Count && is_array($data) && $data[0] === $data[1] ? $data[0] : $data;
	}


	/** Whether the thing stays as it is written for the sake of this value: `keep`, or a map whose every entry is. */
	public function isKept(): bool
	{
		return $this->keep || ($this->domain instanceof Map && array_all($this->content, fn(self $entry) => $entry->isKept()));
	}


	/** The word: of a single word, the first of a tolerance, or the word of a count. */
	public function getWord(): string
	{
		$this->checkNotKept();
		if ($this->domain instanceof Count && is_string($this->content)) {
			return $this->content;
		}

		$this->checkDomain(Words::class);
		return $this->content[0];
	}


	/**
	 * The words of a tolerance, the first being the one written where none matches.
	 * @return list<string>
	 */
	public function getWords(): array
	{
		$this->checkNotKept();
		$this->checkDomain(Words::class);
		return $this->content;
	}


	public function getShape(): string
	{
		$this->checkNotKept();
		$this->checkDomain(Shapes::class);
		return $this->content;
	}


	/**
	 * The count as a range, an exact one having both ends the same and an open one null at the top.
	 * @return array{int, ?int}
	 */
	public function getCount(): array
	{
		$this->checkNotKept();
		$this->checkDomain(Count::class);
		return is_string($this->content) ? throw new \LogicException("The count is the word `$this->content`.") : $this->content;
	}


	public function getText(): string
	{
		$this->checkNotKept();
		$this->checkDomain(Text::class);
		return $this->content;
	}


	public function getFlag(): bool
	{
		$this->checkNotKept();
		$this->checkDomain(Flag::class);
		return $this->content;
	}


	/** @return list<string> */
	public function getNames(): array
	{
		$this->checkNotKept();
		$this->checkDomain(Names::class);
		return $this->content;
	}


	/**
	 * The entries of a map by their names or patterns, withdrawn ones included as `keep`.
	 * @return array<string, self>
	 */
	public function getEntries(): array
	{
		$this->checkNotKept();
		if (!$this->holdsEntries()) {
			throw new \LogicException('The value is no map.');
		}

		return $this->content;
	}


	/** Whether the content is a map of entries, each a Value. */
	private function holdsEntries(): bool
	{
		return !$this->keep && $this->domain instanceof Map;
	}


	/** @param class-string<Domain> $class */
	private function checkDomain(string $class): void
	{
		if (!$this->domain instanceof $class) {
			throw new \LogicException('The value is of ' . $this->domain::class . ', not of ' . $class . '.');
		}
	}


	private function checkNotKept(): void
	{
		if ($this->keep) {
			throw new \LogicException('The value is `keep`; ask isKept() first.');
		}
	}
}
