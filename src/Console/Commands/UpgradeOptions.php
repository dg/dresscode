<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use Nette\CommandLine\Attributes as Cli;
use function in_array;


/**
 * The inputs of `upgrade`: of the offer, and of `--today`, which runs over the files as `fix` does.
 * @internal
 */
final readonly class UpgradeOptions
{
	public function __construct(
		#[Cli\Flag('Upgrade the code to the lowest versions of PHP and of the libraries `composer.json` allows, after raising them: the set `deprecations` and the packages of upgrading data with the types of the code, in the style the project keeps')]
		public ?bool $today = null,
		/** @var list<string> */
		#[Cli\Option('Files or directories; the configured paths when omitted', valueName: 'path', repeatable: true)]
		public array $paths = [],
		#[Cli\Option(
			'Output format; the offer is written as `console` or `json`, `--today` in any format `fix` writes',
			alias: '-f',
			enum: ['console', 'json', 'agent', 'lines', 'github', 'checkstyle'],
		)]
		public ?string $format = null,
		#[Cli\Flag(
			'Measure with the types of the code, which PHPStan beside DressCode gives, without asking; `--no-types` without them',
			negatable: true,
		)]
		public ?bool $types = null,
		#[Cli\Flag('Measure without asking the network: what Packagist offers as remembered, what Composer knows from the lock')]
		public ?bool $offline = null,
		#[Cli\Flag('With `--today`, also write what newer PHP offers, the set `modernizations`')]
		public ?bool $modernize = null,
		#[Cli\Flag('With `--today`, ask about every risky fix left, one at a time with its diff, and make those accepted; needs an interactive terminal')]
		public ?bool $askRisky = null,
		#[Cli\Flag('With `--today`, install the upgrading data the libraries of the project need without asking, outside a terminal too')]
		public ?bool $yes = null,
		/** @var list<string|true> */
		#[Cli\Option(
			'With `--today`, also make the fixes that may change what the code does, of every rule, or with `=name` of that decision, rule, preset or package; they are reported either way',
			valueName: 'name',
			valueOptional: true,
			repeatable: true,
		)]
		public array $fixRisky = [],
		/** @var list<string> */
		#[Cli\Option(
			'With `--today`, only these: a package of upgrading data for its maps and the rules reading them, or a decision, a section, a rule or a preset',
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
		#[Cli\Flag('With `--today`, process every file, even one whose content is known to be clean')]
		public ?bool $noCache = null,
		/** the address of the parent; a worker started by WorkerPool */
		#[Cli\Option(hidden: true)]
		public ?string $worker = null,
		/** the file the parent wrote the run of a worker of the estimate into */
		#[Cli\Option(hidden: true)]
		public ?string $layer = null,
		/** a file to write where the time of the run went, see Profiler */
		#[Cli\Option(hidden: true)]
		public ?string $profile = null,
	) {
	}


	/** Why the options given do not go together, as the command line writes them; null where they do. */
	public function findMisuse(?string $target): ?string
	{
		$todayOption = array_find_key([
			'--modernize' => $this->modernize,
			'--ask-risky' => $this->askRisky,
			'--yes' => $this->yes,
			'--fix-risky' => $this->fixRisky,
			'--only' => $this->only,
			'--no-cache' => $this->noCache,
		], fn(mixed $value) => $value !== null && $value !== [] && $value !== false);
		$measureOption = match (true) {
			$this->types === true => '--types',
			$this->types === false => '--no-types',
			(bool) $this->offline => '--offline',
			default => null,
		};
		$format = $this->format ?? 'console';
		return match (true) {
			$this->today && $target !== null
				=> '`--today` upgrades to the versions `composer.json` allows, so it takes no target; name the paths with `--paths`.',
			$this->today && $measureOption !== null
				=> "`$measureOption` belongs to the estimate; `--today` runs with the types and installs what it needs.",
			$this->today => null,
			$todayOption !== null => "`$todayOption` goes with `--today`; without it `upgrade` only measures.",
			!in_array($format, ['console', 'json'], true)
				=> "The offer is written as `console` or `json`; `$format` is a format of `--today`.",
			default => null,
		};
	}


	/** The run over the files `--today` makes. */
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
