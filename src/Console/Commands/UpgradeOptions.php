<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Upgrade\PlanFile;
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
			'Output format; the offer, the plan and a run of it are written as `console`, `agent` or `json`, `--today` in any format `fix` writes',
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
		#[Cli\Flag('Write the plan of the upgrade, step by step, changing nothing')]
		public ?bool $plan = null,
		#[Cli\Flag('Also save the plan into `dresscode-upgrade.neon`, which the team may edit and `--continue` runs')]
		public ?bool $savePlan = null,
		#[Cli\Flag('Run the plan step by step over the working tree, stopping where a human or Composer is needed')]
		public ?bool $run = null,
		#[Cli\Flag('Run what is left of the plan `dresscode-upgrade.neon` saved, as the code now stands')]
		public ?bool $continue = null,
		#[Cli\Flag('List what the fixes of the plan `dresscode-upgrade.neon` saved leave for you in the code as it stands, changing nothing')]
		public ?bool $tasks = null,
		#[Cli\Option(
			'With `--run` or `--continue`, run that step of the plan alone',
			valueName: 'n',
			normalizer: [RunOptions::class, 'parseJobs'],
		)]
		public ?int $step = null,
		#[Cli\Flag('With `--run` or `--continue`, commit every topic with the files it changed')]
		public ?bool $commit = null,
		#[Cli\Option(
			'The command testing the project, `{junit}` standing for the JUnit file it writes; a step whose tests newly fail goes back',
			valueName: 'command',
		)]
		public ?string $testCommand = null,
		#[Cli\Option('The quicker command testing the project after every step, `{junit}` as in `--test-command`', valueName: 'command')]
		public ?string $quickTestCommand = null,
		#[Cli\Flag('With `--today`, also write what newer PHP offers, the set `modernizations`')]
		public ?bool $modernize = null,
		#[Cli\Flag('With `--today`, ask about every risky fix left, one at a time with its diff, and make those accepted; needs an interactive terminal')]
		public ?bool $askRisky = null,
		#[Cli\Flag('Without asking, outside a terminal too: with `--today` install the upgrading data the libraries of the project need, with `--run` or `--continue` raise the constraints of Composer and update')]
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
			'With `--today`, exit with `1` when more than `n` warnings are left, as in CI',
			valueName: 'n',
			normalizer: [CheckOptions::class, 'parseMaxWarnings'],
		)]
		public ?int $maxWarnings = null,
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


	/** Whether the command makes a plan of the upgrade, to show, to save or to run. */
	public function makesPlan(): bool
	{
		return $this->plan || $this->savePlan || $this->run;
	}


	/** Why the options given do not go together, as the command line writes them; null where they do. */
	public function findMisuse(?string $target): ?string
	{
		$given = fn(array $options) => array_find_key($options, fn(mixed $value) => $value !== null && $value !== [] && $value !== false);
		$todayOption = $given([
			'--modernize' => $this->modernize,
			'--ask-risky' => $this->askRisky,
			'--fix-risky' => $this->fixRisky,
			'--only' => $this->only,
			'--no-cache' => $this->noCache,
			'--max-warnings' => $this->maxWarnings,
		]);
		$planOption = $given([
			'--plan' => $this->plan,
			'--save-plan' => $this->savePlan,
			'--run' => $this->run,
			'--continue' => $this->continue,
			'--tasks' => $this->tasks,
			'--step' => $this->step,
			'--commit' => $this->commit,
			'--test-command' => $this->testCommand,
			'--quick-test-command' => $this->quickTestCommand,
		]);
		$measureOption = match (true) {
			$this->types === true => '--types',
			$this->types === false => '--no-types',
			(bool) $this->offline => '--offline',
			default => null,
		};
		$runs = $this->run || $this->continue;
		$format = $this->format ?? 'console';
		return match (true) {
			$this->today && $target !== null
				=> '`--today` upgrades to the versions `composer.json` allows, so it takes no target; name the paths with `--paths`.',
			$this->today && $planOption !== null => "`$planOption` belongs to the plan of an upgrade, and `--today` makes none.",
			$this->today && $measureOption !== null
				=> "`$measureOption` belongs to the estimate; `--today` runs with the types and installs what it needs.",
			$this->today => null,
			$todayOption !== null => "`$todayOption` goes with `--today`; without it `upgrade` only measures.",
			($this->step !== null || $this->commit || $this->yes) && !$runs
				=> '`--step`, `--commit` and `--yes` go with `--run` or `--continue`, which run the plan.',
			!in_array($format, ['console', 'agent', 'json'], true)
				=> "The offer is written as `console`, `agent` or `json`; `$format` is a format of `--today`.",
			($this->testCommand ?? $this->quickTestCommand) !== null && !$this->makesPlan()
				=> '`--test-command` and `--quick-test-command` go into a plan made with `--plan`, `--save-plan` or `--run`.',
			$this->continue && ($target !== null || $this->makesPlan())
				=> '`--continue` runs the saved plan as it is, so it takes no target and makes no plan; edit `'
					. PlanFile::Name
					. '` instead.',
			$this->tasks && ($target !== null || $this->makesPlan() || $runs)
				=> '`--tasks` lists what the saved plan leaves, so it takes no target and makes or runs no plan.',
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
