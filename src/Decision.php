<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * A key of the configuration saying how one thing in the code is written: its path, which is the identity of
 * everything a user ever sees about it, the values it takes, and the words `explain` and the reference print.
 * A requirement turns its rule on wherever it is not `keep`; a parameter only refines one and turns nothing on;
 * a fact is a key of the environment whose guard the rule is.
 */
final readonly class Decision
{
	public function __construct(
		/** `section.key`, deeper through a structure, every link an identifier */
		public string $path,
		public Domain $domain,
		/** what the rule does where the key is not `keep`, a sentence without a trailing period */
		public string $description,
		/** @var list<string>  what the key is not about, in sentences `explain` prints; never an action of its own */
		public array $notes = [],
		/** the value only refines a requirement and turns nothing on, so it takes no `keep` */
		public bool $parameter = false,
		/** the path is a key of the environment the rule guards, which `--only` never narrows away */
		public bool $fact = false,
		/** what a parameter or a fact is where no layer says it, as a layer would write it; a requirement nobody names requires nothing */
		public mixed $default = null,
	) {
		if (!preg_match('~^[a-zA-Z_]\w*(\.[a-zA-Z_]\w*)+$~D', $path)) {
			throw new \InvalidArgumentException("Decision path `$path` is not `section.key`, every link an identifier.");
		} elseif ($parameter && $fact) {
			throw new \InvalidArgumentException("Decision `$path` is a parameter or a fact, not both.");
		} elseif ($parameter && $default === null) {
			throw new \InvalidArgumentException("Parameter `$path` must have a default.");
		} elseif (!$parameter && !$fact && $default !== null) {
			throw new \InvalidArgumentException("Requirement `$path` has no default, one nobody names requiring nothing.");
		}
	}


	/** Whether the decision turns its rule on wherever it is not `keep`. */
	public function isRequirement(): bool
	{
		return !$this->parameter && !$this->fact;
	}


	/** Whether the value as a whole may be `keep`: of a requirement, unless its domain is a table, which withdraws its entries one by one. */
	public function takesKeep(): bool
	{
		return $this->isRequirement() && $this->domain->takesKeep();
	}


	/** The values the decision takes in prose, `keep` among them where it is taken. */
	public function describeValues(): string
	{
		return $this->domain->describe() . ($this->takesKeep() ? '; `keep`' : '');
	}


	/**
	 * The value as a layer wrote it, normalized; `keep` is taken by a requirement alone.
	 * @throws ConfigurationException
	 */
	public function accept(mixed $raw): Value
	{
		return $this->domain->accept($raw, $this->path, keep: $this->takesKeep());
	}


	/** The value where no layer says it: the default of a parameter or a fact, `keep` of a requirement. */
	public function getDefault(): Value
	{
		return $this->default === null ? Value::keep($this->domain) : $this->accept($this->default);
	}


	/**
	 * The decision as data: its kind, its domain, whether it takes `keep`, its description, the values it takes
	 * in prose, its notes and its default.
	 * @return array{kind: string, domain: array<string, mixed>, keep: bool, description: string, values: string, notes: list<string>, default: mixed}
	 */
	public function toArray(): array
	{
		return [
			'kind' => match (true) {
				$this->parameter => 'parameter',
				$this->fact => 'fact',
				default => 'requirement',
			},
			'domain' => $this->domain->toArray(),
			'keep' => $this->takesKeep(),
			'description' => $this->description,
			'values' => $this->describeValues(),
			'notes' => $this->notes,
			'default' => $this->default,
		];
	}
}
