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
	) {
	}


	/** The layer as a value is said by it: `the configuration`, `dresscode/nette`, `upgrading.neon of acme/mailer`. */
	public function describe(): string
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
	public function format(): string
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
