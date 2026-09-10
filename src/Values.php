<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;

use Nette\Utils\Helpers;


/**
 * The decided values for one file, and the mask of the run over them. Two questions are answered apart: what
 * the project resolved (`get()`), which the code a rule writes follows whatever the run reports, and whether a
 * requirement is reported and repaired in this run (`isSelected()`), which narrowing the run takes away
 * without changing a single value.
 */
final readonly class Values
{
	/** @internal made by the resolver */
	public function __construct(
		/** @var array<string, Decision>  every decision the catalogue knows, by its path */
		private array $decisions,
		/** @var array<string, Value>  what the layers said, by the path of the decision */
		private array $values = [],
		/** @var ?list<string>  the paths of decisions and the prefixes of sections or structures the run is narrowed to; null for all */
		private ?array $selection = null,
	) {
	}


	/**
	 * What the project resolved for the decision, its default where no layer said anything.
	 * @throws ConfigurationException  for a path the catalogue does not know
	 */
	public function get(string $path): Value
	{
		return $this->values[$path] ?? $this->getDecision($path)->getDefault();
	}


	/** What the project resolved for the decision, null where it is `keep`, so that a rule reads `find($path)?->getWord()`. */
	public function find(string $path): ?Value
	{
		$value = $this->get($path);
		return $value->isKept() ? null : $value;
	}


	/**
	 * The entries of a map the project resolved, without those a layer withdrew: as the grammar of its domain reads
	 * them as written, or normalized by the domain of its values where it has no grammar.
	 * @return array<mixed>
	 * @throws ConfigurationException  for a path that is no map, or an entry the grammar does not take
	 */
	public function readMap(string $path): array
	{
		$domain = $this->getDecision($path)->domain;
		$value = $this->get($path);
		if (!$domain instanceof Domains\Map) {
			throw new \LogicException("Decision `$path` is no map.");
		}

		$entries = $value->isKept() ? [] : array_map(
			fn(Value $entry) => $domain->grammar === null ? $entry->toData() : $entry->raw,
			array_filter($value->getEntries(), fn(Value $entry) => !$entry->isKept()),
		);
		return $domain->read($entries, $path);
	}


	/** Whether the requirement is `keep`, or named by no layer. */
	public function isKept(string $path): bool
	{
		return $this->get($path)->isKept();
	}


	/**
	 * Whether the requirement is reported and repaired in this run: it is not `keep` and lies in the mask. A
	 * fact is guarded whatever the mask says; a parameter is never selected, it reports nothing of its own.
	 */
	public function isSelected(string $path): bool
	{
		$decision = $this->getDecision($path);
		if ($decision->parameter || $this->isKept($path)) {
			return false;
		} elseif ($decision->fact || $this->selection === null) {
			return true;
		}

		return array_any($this->selection, fn($prefix) => $path === $prefix || str_starts_with($path, "$prefix."));
	}


	/** @throws ConfigurationException */
	private function getDecision(string $path): Decision
	{
		if (isset($this->decisions[$path])) {
			return $this->decisions[$path];
		}

		$hint = Helpers::getSuggestion(array_keys($this->decisions), $path);
		throw new ConfigurationException("Decision `$path` is unknown" . ($hint === null ? '.' : "; write `$hint`."));
	}
}
