<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Config;


/**
 * Who says a value: the configuration, the command line, an override, a preset or the upgrading data of a package.
 */
final readonly class Layer
{
	public function __construct(
		public LayerKind $kind,
		/** the paths of an override, joined by commas, the name of a preset, or the upgrading file of a package */
		public ?string $name = null,
		/** the package an upgrading file ships with */
		public ?string $package = null,
		/** the requirement that lays the upgrading file and what it says is reported under, null for the flag `upgrading.packages` */
		public ?string $decision = null,
		/** the package whose versions the section of an upgrading file is of, `php` for PHP itself */
		public ?string $library = null,
		/** the version of the section of an upgrading file */
		public ?string $since = null,
		/** the version the fixes of the entries of the section are right from, where it is older than the section */
		public ?string $from = null,
	) {
	}


	/** The layer of one section of the upgrading file this layer is, with the version its entries are right from. */
	public function withSection(string $library, string $since, ?string $from): self
	{
		return new self($this->kind, $this->name, $this->package, $this->decision, $library, $since, $from);
	}


	/**
	 * The layer as a value is said by it: `the configuration`, `dresscode/nette`, `upgrading.neon of acme/mailer`,
	 * `application.neon of dresscode/upgrade-nette, since 3.2`.
	 */
	public function describe(): string
	{
		return $this->describeSource() . ($this->since === null ? '' : ", since $this->since");
	}


	private function describeSource(): string
	{
		return match ($this->kind) {
			LayerKind::Configuration => 'the configuration',
			LayerKind::CommandLine => 'the command line',
			LayerKind::Caller => 'the caller',
			LayerKind::Override => "the override for $this->name",
			LayerKind::Preset => (string) $this->name,
			LayerKind::Package => match (true) {
				$this->name === null => "DressCode for $this->package",
				$this->package === null => $this->name,
				default => "$this->name of $this->package",
			},
		};
	}


	/** The layer as a message names it, with the code it names in backticks. */
	public function formatReference(): string
	{
		return match ($this->kind) {
			LayerKind::Override => 'the override for `' . str_replace(', ', '`, `', (string) $this->name) . '`',
			LayerKind::Preset => "preset `$this->name`",
			LayerKind::Package => $this->name !== null && $this->package !== null
				? "`$this->name` of `$this->package`"
				: '`' . $this->describe() . '`',
			default => $this->describe(),
		};
	}


	/** Whether the project writes the layer, not a preset or a package. */
	public function isProject(): bool
	{
		return match ($this->kind) {
			LayerKind::Configuration, LayerKind::CommandLine, LayerKind::Override => true,
			default => false,
		};
	}
}
