<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use Nette\CommandLine\Attributes as Cli;


/**
 * The inputs of measuring an upgrade, `upgrade estimate`.
 * @internal
 */
final readonly class EstimateOptions
{
	public function __construct(
		/** @var list<string> */
		#[Cli\Option('Files or directories within the configured paths; all of them when omitted', valueName: 'path', repeatable: true)]
		public array $paths = [],
		#[Cli\Option(
			'Output format: `console`, or `json` for a tool or an AI agent, which never asks',
			alias: '-f',
			enum: ['console', 'json'],
		)]
		public ?string $format = null,
		#[Cli\Flag(
			'Measure with the types of the code from PHPStan, without asking; `--no-types` without them',
			negatable: true,
		)]
		public ?bool $types = null,
		#[Cli\Flag('Measure without asking the network: what Packagist offers as remembered, what Composer knows from the lock')]
		public ?bool $offline = null,
		#[Cli\Option(
			'Number of worker processes; by default the number of processors, at most one per four files; `1` runs in-process',
			valueName: 'n',
			normalizer: [RunOptions::class, 'parsePositiveInt'],
		)]
		public ?int $jobs = null,
		/** the address of the parent; a worker started by WorkerPool */
		#[Cli\Option(hidden: true)]
		public ?string $worker = null,
		/** the file the parent wrote the check a worker of the estimate repeats into */
		#[Cli\Option(hidden: true)]
		public ?string $checkFile = null,
	) {
	}
}
