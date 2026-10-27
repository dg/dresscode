<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use Nette\CommandLine\Attributes as Cli;


/**
 * The inputs of `upgrade`, which runs over the files as `fix` does.
 * @internal
 */
final readonly class UpgradeOptions
{
	public function __construct(
		/** @var list<string> */
		#[Cli\Option('Files or directories; the configured paths when omitted', valueName: 'path', repeatable: true)]
		public array $paths = [],
		#[Cli\Option(
			'Output format, any `fix` writes',
			alias: '-f',
			enum: ['console', 'json', 'agent', 'lines', 'github', 'checkstyle'],
		)]
		public ?string $format = null,
		#[Cli\Flag('Also write what newer PHP offers, the set `modernizations`')]
		public ?bool $modernize = null,
		#[Cli\Flag('Ask about every risky fix left, one at a time with its diff, and make those accepted; needs an interactive terminal')]
		public ?bool $askRisky = null,
		#[Cli\Flag('Install the upgrading data the libraries of the project need without asking, outside a terminal too')]
		public ?bool $yes = null,
		/** @var list<string|true> */
		#[Cli\Option(
			'Also make the fixes that may change what the code does, of every rule, or with `=name` of that decision, rule, preset or package; they are reported either way',
			valueName: 'name',
			valueOptional: true,
			repeatable: true,
		)]
		public array $fixRisky = [],
		/** @var list<string> */
		#[Cli\Option(
			'Only these: a package of upgrading data for its maps and the rules reading them, or a decision, a section, a rule or a preset',
			valueName: 'name',
			repeatable: true,
		)]
		public array $only = [],
		#[Cli\Option(
			'Number of worker processes; by default the number of processors, at most one per four files; `1` runs in-process',
			valueName: 'n',
			normalizer: [RunOptions::class, 'parseJobs'],
		)]
		public ?int $jobs = null,
		#[Cli\Flag('Process every file, even one whose content is known to be clean')]
		public ?bool $noCache = null,
		/** the address of the parent; a worker started by WorkerPool */
		#[Cli\Option(hidden: true)]
		public ?string $worker = null,
		/** a file to write where the time of the run went, see Profiler */
		#[Cli\Option(hidden: true)]
		public ?string $profile = null,
	) {
	}


	/** The run over the files. */
	public function toRunOptions(): RunOptions
	{
		return new RunOptions(
			$this->paths,
			$this->format,
			noCache: $this->noCache,
			jobs: $this->jobs,
			worker: $this->worker,
			profile: $this->profile,
		);
	}
}
