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
use DressCode\Interop\{PhpCodeSniffer, Translation};
use DressCode\Measuring\{Answer, Budget, InProcessProbeRunner, ProcessProbeRunner, Project, Proposal, Question, Questions, Scope};
use Nette\CommandLine\Attributes as Cli;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function count;


/**
 * The command `init`: measures how the project writes what can be measured and writes dresscode.neon with it, saying
 * what it does phase by phase and asking where the measuring left the choice to the user; without a terminal, with
 * `--yes` or for an agent it takes the recommended answers and says how to give another. A configuration that exists
 * is never overwritten.
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
		#[Cli\Flag('Take the recommended answers without asking, as without a terminal')]
		bool $yes = false,
		#[Cli\Option(
			'Output format: `console` to read and answer, `agent` for an AI agent, which takes the recommended answers',
			alias: '-f',
			enum: ['console', 'agent'],
		)]
		string $format = 'console',
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
		$status = new StatusLine($console, active: $format === 'console');
		$printer = new InitPrinter($console);
		$interactive = $format === 'console' && !$yes && $this->context->prompt->isInteractive();
		$write(CommandContext::formatName($console) . '  looking at ' . FileSystem::platformSlashes($root) . " to write its `dresscode.neon`\n\n");

		$status->show('Project', 'looking for the PHP files');
		$project = Project::detect($root);
		$scope = Scope::fromProject($root, $project->formatterConfig);
		$write($printer->printScope($project, $scope));

		$runner = WorkerPool::buildPhpCommand() === null
			? new InProcessProbeRunner($root, $status->advance(...))
			: ProcessProbeRunner::create($root, $status->advance(...));
		$onPhase = fn(string $text) => $status->show('Measuring', $text);
		$proposal = Proposal::measure($project, $scope, $runner, $presets, $onPhase);
		$standardQuestion = Questions::findStandardQuestion($proposal);
		if ($interactive && $standardQuestion !== null) {
			$console->clearStatus();
			$answer = $this->ask($printer, $standardQuestion);
			$proposal = $answer->presets === null ? $proposal : Proposal::measure($project, $scope, $runner, $answer->presets, $onPhase);
		}

		foreach ($set as $spec) {
			[$path, $value] = explode('=', $spec, 2);
			$proposal->answer($path, Neon::decode($value));
		}

		$limit = new Budget(count($scope->files), $budget);
		$status->show('Checking', 'the configuration over all ' . count($scope->files) . ' files');
		$proposal->verify($runner, $limit);
		$questions = Questions::findAll($proposal, $this->readFormatterRules($project, $printer, $interactive));
		$reverify = function (array $answers) use ($proposal, $runner, $limit, $status, $scope): bool {
			foreach ($answers as $answer) {
				$proposal->apply($answer);
			}

			if (!array_filter($answers, fn(Answer $answer) => !$answer->isNone())) {
				return false;
			}

			$status->show('Checking', 'the configuration with the answers over all ' . count($scope->files) . ' files');
			$proposal->verify($runner, $limit);
			return true;
		};

		if ($interactive) {
			// the values listed are those the budget left, which the verification decides
			$write($printer->printMeasurements($proposal) . $printer->printVerification($proposal));
			if ($reverify(array_map(fn(Question $question) => $this->ask($printer, $question), $questions))) {
				$write("\n" . $printer->printVerification($proposal));
			}
		} else {
			// without a terminal the recommended answers are taken before anything is listed
			$reverify(array_map(fn(Question $question) => $question->getRecommended(), $questions));
			$write($printer->printMeasurements($proposal) . $printer->printVerification($proposal));
			$write($printer->printQuestions([...($standardQuestion === null ? [] : [$standardQuestion]), ...$questions]));
		}

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


	/** Asks the question, an empty answer or the end of the input taking the recommended one. */
	private function ask(InitPrinter $printer, Question $question): Answer
	{
		$this->context->out->write("\n" . Markup::highlightCode($this->context->out, $printer->printQuestion($question)));
		$choices = [];
		foreach ($question->answers as $i => $answer) {
			$choices[$answer->key] = $answer->label . ($i === 0 ? ' (recommended)' : '');
		}

		$key = $this->context->prompt->choose('Your choice', $choices, $question->getRecommended()->key);
		return $question->findAnswer((string) $key) ?? $question->getRecommended();
	}


	/**
	 * The rules of the formatter of the project, which a value of the code may contradict: data are read, a
	 * configuration of PHP CS Fixer, which reading runs, only when the user agrees to it in a terminal.
	 */
	private function readFormatterRules(Project $project, InitPrinter $printer, bool $interactive): ?Translation
	{
		$config = $project->formatterConfig;
		if ($config === null) {
			return null;
		} elseif ($config->formatter->runsCode() && (!$interactive || $this->context->prompt->choose(
			"\n`$config->file` is PHP, which reading runs. Read it to compare its rules with your code?",
			['y', 'n'],
			'n',
		) !== 'y')
		) {
			return null;
		}

		try {
			return $config->translate();
		} catch (ConfigurationException $e) {
			$this->context->out->write($printer->printUnreadable($config, $e->getMessage()));
			return null;
		}
	}
}
