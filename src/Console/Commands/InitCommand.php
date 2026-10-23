<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\{Loader, PluginRegistry, RunnerFactory};
use DressCode\ConfigurationException;
use DressCode\Console\{CommandContext, InitPrinter, Markup, StatusLine};
use DressCode\Engine\{Helpers, WorkerPool};
use DressCode\Interop\PhpCodeSniffer;
use DressCode\Measuring\{Budget, InProcessProbeRunner, ProcessProbeRunner, Project, Proposal, Scope};
use Nette\CommandLine\Attributes as Cli;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function count;


/**
 * The command `init`: measures how the project writes what can be measured and writes dresscode.neon with it, saying
 * what it does phase by phase; a configuration that exists is never overwritten.
 * @internal
 */
final readonly class InitCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	/**
	 * @param  list<string>  $use
	 * @param  list<string>  $set
	 */
	#[Cli\Command('Measure the code of the project and write a `dresscode.neon` to fit')]
	public function init(
		#[Cli\Option(
			'The standard and the sets to write; the standard nearest to the code by default',
			valueName: 'name',
			repeatable: true,
			normalizer: [ConfigOptions::class, 'checkPreset'],
		)]
		array $use = [],
		#[Cli\Option(
			'The files the first fix may change, a decision whose fix would change more being left unchecked; 1 % of the files by default, 2 to 20',
			valueName: 'files',
			normalizer: [RunOptions::class, 'parseJobs'],
		)]
		?int $budget = null,
		#[Cli\Option(
			'Write a decision as `path=value`, the value in NEON, whatever the code says of it: the answer to what init asks',
			valueName: 'path=value',
			repeatable: true,
			normalizer: [ConfigOptions::class, 'checkDecision'],
		)]
		array $set = [],
	): int
	{
		$root = Helpers::canonicalizePath($this->context->workingDirectory);
		$existing = Loader::listFiles($root);
		try {
			// over a configuration that exists init measures what has moved since, against the standard that file names
			$current = $existing ? Loader::loadFile("$root/$existing[0]") : null;
		} catch (ConfigurationException) {
			$current = null;
		}

		$presets = $use ?: $current?->use ?: null;
		$registry = new PluginRegistry;
		foreach ($presets ?? [] as $preset) {
			$registry->resolvePreset($preset); // a misspelled one before the measuring, not after it
		}

		$console = $this->context->out;
		$write = fn(string $text) => $console->write(Markup::highlightCode($console, $text));
		$status = new StatusLine($console);
		$printer = new InitPrinter($console);
		$write(CommandContext::formatName($console) . '  looking at ' . FileSystem::platformSlashes($root) . " to write its `dresscode.neon`\n\n");

		$status->show('Project', 'looking for the PHP files');
		$project = Project::detect($root);
		$scope = Scope::fromProject($root, $project->formatterConfig);
		$write($printer->printScope($project, $scope));

		$runner = WorkerPool::buildPhpCommand() === null
			? new InProcessProbeRunner($root)
			: ProcessProbeRunner::create($root);
		$proposal = Proposal::measure($project, $scope, $runner, $presets, fn(string $text) => $status->show('Measuring', $text));
		foreach ($set as $spec) {
			[$path, $value] = explode('=', $spec, 2);
			$proposal->answer($path, Neon::decode($value));
		}

		$status->show('Checking', 'the configuration over all ' . count($scope->files) . ' files');
		$proposal->verify($runner, new Budget(count($scope->files), $budget));
		// the values listed are those the budget left, which the verification decides
		$write($printer->printMeasurements($proposal));
		$write($printer->printVerification($proposal));
		$write($printer->printQuestions($proposal));

		$proposal->checkWritten();
		if ($existing) {
			$write("\n" . ($current === null
				? "`$existing[0]` exists, so nothing is written; it is no configuration init can compare with.\n"
				: $printer->printShifts($proposal, (new RunnerFactory)->resolve($current, $root)->resolvedConfig, $existing[0])));
			return 0;
		}

		FileSystem::write("$root/dresscode.neon", $proposal->toDraft()->toNeon());
		$write("\n" . $printer->printNext($proposal));
		$write($printer->printAlso(
			$project,
			$project->formatterConfig === null ? 0 : PhpCodeSniffer::countSuppressions($root, $scope->files),
		));
		return 0;
	}
}
