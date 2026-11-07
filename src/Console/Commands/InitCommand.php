<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\{Config, ConfigurationException, Plugin};
use DressCode\Config\{ConfigResolver, Loader, PluginRegistry, RunnerFactory};
use DressCode\Console\{CommandContext, FormatterChoice, InitPrinter, Markup, StatusLine};
use DressCode\Engine\{Helpers, SuppressionMigration, WorkerPool};
use DressCode\Interop\Translation;
use DressCode\Measuring\{
	Answer, InProcessProbeRunner, LeftOutReason, ProcessProbeRunner, ProjectFacts, Proposal, Question, Questions, Scope,
};
use Nette\CommandLine\{Attributes as Cli, ColorDepth, Console, ParseResult, UsageException};
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use function count, is_string;


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
		ParseResult $args,
		#[Cli\Option(
			'The standard and the sets to write; the standard nearest to the code by default',
			valueName: 'name',
			repeatable: true,
			normalizer: [ConfigOptions::class, 'checkPreset'],
		)]
		array $use = [],
		#[Cli\Option(
			'Limit the files the first fix changes (default 1%, 2 to 20); a decision over it is written as `keep`. The indentation, line endings, long lines and your answers do not count',
			valueName: 'files',
			normalizer: [RunOptions::class, 'parsePositiveInt'],
		)]
		?int $budget = null,
		#[Cli\Option(
			'Write a decision whatever the code says, as `path=value` with the value in NEON; it answers what init asks',
			valueName: 'path=value',
			repeatable: true,
			normalizer: [ConfigOptions::class, 'checkDecision'],
		)]
		array $set = [],
		#[Cli\Flag('Take the recommended answers without asking, as without a terminal')]
		bool $yes = false,
		#[Cli\Flag('Print the configuration instead of writing it')]
		bool $dryRun = false,
		#[Cli\Option(
			'The configuration of PHP CS Fixer, PHP_CodeSniffer or Laravel Pint the project runs; the one found when omitted',
			valueName: 'file',
		)]
		?string $formatter = null,
		#[Cli\Option(
			'Output format: `console` to read and answer, or `json` for a tool or an AI agent, which takes the recommended answers',
			alias: '-f',
			enum: ['console', 'json'],
		)]
		string $format = 'console',
		#[Cli\Option(
			'Number of processors the probes share; by default all of them; `1` runs them in-process',
			valueName: 'n',
			normalizer: [RunOptions::class, 'parsePositiveInt'],
		)]
		?int $jobs = null,
	): int
	{
		$root = Helpers::canonicalizePath($this->context->workingDirectory);
		$file = Loader::findIn($root);
		if ($file !== null && $set) {
			throw new UsageException(
				'`--set` answers what init asks, and over `' . basename($file) . '`, which exists, it asks nothing; edit the file instead.',
				$args->command,
			);
		}

		try {
			// over a configuration that exists init measures what has moved since, against the standard that file names
			$current = $file === null ? null : Loader::loadFile($file);
		} catch (ConfigurationException $e) {
			throw new ConfigurationException(
				'`' . basename($file) . "` exists and cannot be read, so init has nothing to compare with: {$e->getMessage()}",
				previous: $e,
			);
		}

		// a misspelled preset or decision before the measuring, not after it
		$registry = new PluginRegistry;
		foreach ($use as $preset) {
			$registry->resolvePreset($preset);
		}

		$answers = [];
		$catalogue = new ConfigResolver($registry)->getCatalogue();
		foreach ($set as $spec) {
			[$path, $value] = explode('=', $spec, 2);
			$decision = $catalogue->find($path)
				?? throw new UsageException("`--set $spec` names no decision; `dresscode catalogue` lists them.", $args->command);
			$answers[$path] = Neon::decode($value);
			$decision->accept($answers[$path]);
		}

		// the text of the format `json` is written nowhere, the document at the end saying it all
		$json = $format === 'json';
		$console = $json
			? new Console(fopen('php://memory', 'w+') ?: throw new \RuntimeException, ColorDepth::None, terminal: false)
			: $this->context->out;
		$status = new StatusLine($console, active: $format === 'console');
		$printer = new InitPrinter($console);
		// over a configuration that exists nothing is written, so nothing is asked and no answer is taken
		$interactive = $current === null && $format === 'console' && !$yes && $this->context->prompt->isInteractive();
		$console->write(CommandContext::formatName($console) . Markup::highlightCode($console, $current === null
			? '  looking at ' . FileSystem::platformSlashes($root) . " to write its `dresscode.neon`\n\n"
			: '  comparing the code of ' . FileSystem::platformSlashes($root) . ' with its `' . basename((string) $file) . "`\n\n"));

		$status->show('Project', 'looking for the PHP files');
		$choice = $this->context->chooseFormatterConfig($root, $formatter, $interactive);
		$project = ProjectFacts::detect($root, $choice->config);
		// a configuration that exists says the files it checks, which is what the code is compared with it over
		$scope = $current === null ? Scope::fromProject($root, $project->formatterConfig) : Scope::fromConfig($root, $current);
		$console->write($printer->printScope($project, $scope, $current === null ? $choice->unchosen : []));
		if ($scope->files === [] && in_array(LeftOutReason::Overridden, $scope->leftOut, true)) {
			$console->clearStatus();
			$console->write($printer->printNothingCompared(basename((string) $file)));
			if ($json) {
				$this->context->writeJson(InitPrinter::toScopeArray($root, $scope));
			}

			return 2;

		} elseif ($scope->files === []) {
			throw new ConfigurationException('The project has no PHP file to measure in ' . implode(', ', $scope->listPaths()) . ', so init has nothing to make a configuration of; write `dresscode.neon` with the standard it is to keep, `use: nette`, or run init in the root of the code.');
		}

		// a probe names the plugins of the configuration by their class, which a configuration of PHP may declare itself and
		// so only in this process
		$plugins = array_map(fn(string|Plugin $plugin) => is_string($plugin) ? $plugin : $plugin::class, $current->plugins ?? []);
		$runner = WorkerPool::buildPhpCommand() === null
			|| $jobs === 1
			|| ($plugins !== [] && Loader::detectFormat((string) $file) === 'php')
			? new InProcessProbeRunner($root, $status->advance(...))
			: ProcessProbeRunner::create($root, $status->advance(...), $jobs);
		$presets = $use ?: ($current?->use ? [...$plugins, ...$current->use] : null);
		$proposal = Proposal::measure($project, $scope, $runner, $presets, $budget, $status->show(...));
		if ($proposal->standard->unsure) {
			$console->clearStatus();
			$console->write($printer->printUnsure($proposal));
			if ($json) {
				$this->context->writeJson($printer->toArray($proposal));
			}

			return 2;

		} elseif ($current !== null) {
			return $this->compare($proposal, $printer, $console, $current, (string) $file, $json);
		}

		$standardQuestion = Questions::findStandardQuestion($proposal);
		if ($interactive && $standardQuestion !== null) {
			$console->clearStatus();
			$presets = $this->ask($printer, $standardQuestion)->measureAgainst;
			$proposal = $presets === null ? $proposal : $proposal->measureAgainst($presets);
		}

		foreach ($answers as $path => $value) {
			$proposal->answer($path, $value);
		}

		$proposal->verify();
		$rules = $this->context->confirmReadingRules($choice, $interactive, 'Read it to compare its rules with your code?', $yes)
			? $this->readFormatterRules($choice, $printer, $console)
			: null;
		$questions = new Questions($rules);
		$asked = [];
		if ($interactive) {
			// the values listed are those the budget left, which the verification decides
			$console->write($printer->printMeasurements($proposal) . $printer->printVerification($proposal));
			$verification = $proposal->verification;
			$questions->askAll($proposal, fn(array $round, bool $following) => $this->askRound($printer, $proposal, $round, $following));
			if ($proposal->verification !== $verification) {
				$console->write("\n" . $printer->printVerification($proposal));
			}
		} else {
			// without a terminal the recommended answers are taken before anything is listed
			$asked = [
				...($standardQuestion === null ? [] : [$standardQuestion]),
				...$questions->askAll(
					$proposal,
					fn(array $round) => array_map(fn(Question $question) => $question->getRecommended(), $round),
				),
			];
			$console->write(
				$printer->printMeasurements($proposal) . $printer->printInstructions($asked) . $printer->printVerification($proposal),
			);
		}

		$proposal->verifyWithTypes();
		$text = $proposal->toDraft()->renderChecked($root);
		if ($dryRun) {
			$console->write("\n" . $printer->printNext($proposal, written: false) . "\n");
			$console->write($text); // as the file writes it, its backticks among it
			if ($json) {
				$this->context->writeJson($printer->toArray($proposal, $asked, $text));
			}

			return $proposal->isPartial() ? 2 : 0;
		}

		// another configuration may have appeared while the code was measured, which a run would read in its place
		$existing = Loader::findIn($root);
		if ($existing !== null || !Helpers::createFile("$root/dresscode.neon", $text)) {
			$name = basename($existing ?? 'dresscode.neon');
			throw new ConfigurationException("`$name` appeared while init measured the code, so nothing was written; run init again to compare it with the code.");
		}

		$suppressions = $project->formatterConfig === null
			? 0
			: new SuppressionMigration($registry->expandSuppressedName(...))->countInFiles($root, $scope->files);
		$console->write(
			"\n" . $printer->printNext($proposal) . $printer->printAlso($project, $suppressions, $rules !== null)
			. $printer->printWritten($proposal, "$root/dresscode.neon"),
		);
		if ($json) {
			$this->context->writeJson($printer->toArray($proposal, $asked, $text, 'dresscode.neon'));
		}

		return $proposal->isPartial() ? 2 : 0;
	}


	/** Compares the code with the configuration that exists, what has moved since, and writes nothing. */
	private function compare(Proposal $proposal, InitPrinter $printer, Console $console, Config $current, string $file, bool $json): int
	{
		$proposal->verify();
		$shifts = $proposal->findShifts((new RunnerFactory)->resolve($current, $proposal->project->root)->resolvedConfig);
		$console->write($printer->printMeasurements($proposal) . $printer->printVerification($proposal) . "\n"
			. $printer->printShifts($proposal, $shifts, basename($file)));
		if ($json) {
			$this->context->writeJson($printer->toArray($proposal, shifts: $shifts));
		}

		return $proposal->isPartial() ? 2 : 0;
	}


	/**
	 * Asks the questions of a round one by one, numbered where there are more of them, a round following from the
	 * answers before said to be one, the example of a question shown with what a fix makes of it.
	 * @param  list<Question>  $round
	 * @return list<Answer>
	 * @throws ConfigurationException
	 */
	private function askRound(InitPrinter $printer, Proposal $proposal, array $round, bool $following): array
	{
		if ($following) {
			$this->context->out->write("\n" . $printer->printNextRound(count($round)));
		}

		$answers = [];
		foreach ($round as $i => $question) {
			$preview = $question->example === null || $question->previewValues === []
				? []
				: $proposal->previewFix($question->previewValues, $question->example);
			$answers[] = $this->ask($printer, $question, $i + 1, count($round), $preview);
		}

		return $answers;
	}


	/**
	 * Asks the question, an empty answer or the end of the input taking the recommended one.
	 * @param  list<string>  $preview
	 */
	private function ask(InitPrinter $printer, Question $question, int $number = 1, int $count = 1, array $preview = []): Answer
	{
		$this->context->out->write("\n" . $printer->printQuestion($question, $number, $count, $preview));
		$choice = $this->context->prompt->choose('Your choice', array_map(strval(...), range(1, count($question->answers))), '1');
		return $question->answers[(int) $choice - 1] ?? $question->getRecommended();
	}


	/** The rules of the formatter of the project, which a value of the code may contradict; null where they cannot be read. */
	private function readFormatterRules(FormatterChoice $choice, InitPrinter $printer, Console $console): ?Translation
	{
		$config = $choice->config ?? throw new \LogicException('No configuration of a formatter was chosen.');
		try {
			return $config->translate();
		} catch (ConfigurationException $e) {
			$console->write($printer->printUnreadable($config, $e->getMessage()));
			return null;
		}
	}
}
