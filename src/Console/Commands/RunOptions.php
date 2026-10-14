<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use Nette\CommandLine\{Attributes as Cli, Normalizers};


/**
 * The inputs of a run over the files of the project, which `check`, `fix` and `baseline` share.
 * @internal
 */
final readonly class RunOptions
{
	public function __construct(
		/** @var list<string> */
		#[Cli\Argument('Files or directories; the configured paths when omitted', repeatable: true)]
		public array $paths = [],
		#[Cli\Option(
			'Output format, `github` by default in GitHub Actions; `bare` prints only what is left to the user and which files were rewritten, so a clean run prints nothing',
			alias: '-f',
			enum: ['console', 'bare', 'github', 'json', 'checkstyle'],
		)]
		public ?string $format = null,
		#[Cli\Flag('Skip a named file that `excludePaths` leaves out or `paths` do not hold, as a hook or an editor passing every file it touches needs')]
		public ?bool $skipExcluded = null,
		#[Cli\Flag('Process every file, even one whose content is known to be clean, and remember what comes out')]
		public ?bool $noCache = null,
		#[Cli\Option(
			'Number of worker processes; by default the number of processors, at most one per four files; `1` runs in-process',
			valueName: 'n',
			normalizer: [self::class, 'parseJobs'],
		)]
		public ?int $jobs = null,
		#[Cli\Flag('Treat a rule breaking its contract as an error, not a warning')]
		public ?bool $strictRules = null,
		/** the address of the parent; a worker started by WorkerPool */
		#[Cli\Option(hidden: true)]
		public ?string $worker = null,
		/** a file to write where the time of the run went, see Profiler */
		#[Cli\Option(hidden: true)]
		public ?string $profile = null,
	) {
	}


	public static function parseJobs(string $value): int
	{
		return Normalizers::int(min: 1)($value);
	}
}
