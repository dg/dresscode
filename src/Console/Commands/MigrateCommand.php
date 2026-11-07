<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\Loader;
use DressCode\Console\{Application, CommandContext, Markup, MigrationPrinter, SuppressionsPrinter};
use DressCode\Engine\{Diff, Helpers, SuppressionMigration};
use DressCode\Interop\FormatterCandidate;
use DressCode\Measuring\{LeftOutReason, Scope};
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use PhpSyntax\{ParseException, Parser, Printer};
use function count, in_array, sprintf;


/**
 * The commands of `migrate`, the move from another formatter: `config` translates a configuration of PHP CS Fixer,
 * PHP_CodeSniffer or Laravel Pint into NEON or PHP by the extension of the file, checked as a run reads it before it
 * is written, what was not carried over being said in the file and no failure; a configuration of PHP CS Fixer is PHP,
 * which runs when it is read, so one the project has is read only after the user agrees. `suppressions` rewrites the
 * phpcs suppression comments of the files to the dresscode form. Both show what they would write with `--dry-run`.
 * @internal
 */
final readonly class MigrateCommand
{
	/** the version of the JSON documents, a key removed, renamed or meaning another thing being a new one */
	public const Version = 1;


	public function __construct(
		private CommandContext $context,
	) {
	}


	#[Cli\Command('Translate a PHP CS Fixer, PHP_CodeSniffer or Laravel Pint configuration')]
	public function config(
		ParseResult $args,
		#[Cli\Argument('Configuration file of PHP CS Fixer, PHP_CodeSniffer or Laravel Pint; the one the project has when omitted')]
		?string $file = null,
		#[Cli\Option('The configuration to write, NEON or PHP by its extension', alias: '-o', valueName: 'file')]
		string $output = 'dresscode.neon',
		#[Cli\Flag('Print the configuration instead of writing it')]
		bool $dryRun = false,
		#[Cli\Option(
			'Output format: `console`, or `json` for a tool or an AI agent, which never asks',
			alias: '-f',
			enum: ['console', 'json'],
		)]
		string $format = 'console',
	): int
	{
		$json = $format === 'json';
		$target = $this->context->resolvePath($output);
		$syntax = strtolower(pathinfo($target, PATHINFO_EXTENSION));
		if (!in_array($syntax, ['neon', 'php'], true)) {
			throw new UsageException(
				"The configuration `$output` is written as NEON or PHP, so its extension is `.neon` or `.php`.",
				$args->command,
			);
		}

		// a file of a name a run looks for collides with any configuration of the directory, a .dist template among them
		$existing = in_array(basename($target), Loader::FileNames, true)
			? Loader::findIn(dirname($target))
			: (file_exists($target) ? $target : null);
		if ($existing !== null && !$dryRun) {
			throw new UsageException(
				'The configuration `' . basename($existing) . '` exists already; remove it, or name another file.',
				$args->command,
			);
		}

		$root = Helpers::canonicalizePath($this->context->workingDirectory);
		if (
			Helpers::canonicalizePath(FileSystem::normalizePath(dirname($target)))
			!== Helpers::canonicalizePath(FileSystem::normalizePath($root))
		) {
			// the paths it writes are relative to the project, and a configuration reads them relative to its own directory
			throw new UsageException(
				"The configuration `$output` would lie outside the root of the project, whose paths it names; write it into the root.",
				$args->command,
			);
		}
		$interactive = !$json && $this->context->prompt->isInteractive();
		$choice = $this->context->chooseFormatterConfig($root, $file, $interactive);
		$config = $choice->config ?? throw new UsageException(
			'The project has no configuration of PHP CS Fixer, PHP_CodeSniffer or Laravel Pint; name the file to translate.',
			$args->command,
		);
		if (!$choice->named) {
			$this->context->writeNote('Found ' . $config->format() . ".\n" . ($choice->unchosen === []
				? ''
				: 'The project has also ' . implode(' and ', array_map(fn(FormatterCandidate $other) => $other->format(), $choice->unchosen))
					. "; the first is taken, and `dresscode migrate config <file>` translates another.\n"));
		}

		if (!$this->context->confirmReadingRules($choice, $interactive)) {
			if (!$interactive) {
				throw new UsageException(
					"`$config->file` is PHP, so reading it means running it, and it is read only when named: `dresscode migrate config $config->file`.",
					$args->command,
				);
			}

			$this->context->writeNote("Nothing written.\n");
			return 0;
		}

		$translation = $config->translate();
		$scope = Scope::fromProject($root, $config);
		foreach ($scope->listExcludePaths() as $path => $why) { // what the formatter left out is left out as it was, the rest is a change
			if ($why !== LeftOutReason::Foreign) {
				$translation->request("`$path` is excluded, $why->value, though {$config->formatter->value} checked it; drop it from `excludePaths` to check it still.");
			}
		}

		$written = count($translation->collectOwnDecisions());
		$draft = $translation->toDraft(sprintf(
			"Translated from `%s` of %s\nby `dresscode migrate config` of DressCode %s.",
			FileSystem::isAbsolute($config->file) ? basename($config->file) : $config->file,
			$config->formatter->value,
			Application::Version,
		));
		$scope->writeInto($draft);
		$text = $draft->renderChecked($root, $syntax);
		if (!$dryRun && !Helpers::createFile($target, $text)) {
			throw new UsageException("The configuration `$output` appeared meanwhile; remove it, or name another file.", $args->command);
		}

		if ($json) {
			$this->context->writeJson([
				'version' => self::Version,
				'root' => $root,
				'source' => $config->file,
				'formatter' => $config->formatter->value,
				'configuration' => $text,
				'written' => $dryRun ? null : $output,
				'rules' => $translation->ruleCount,
				'decisions' => $written,
				'presets' => $translation->presets,
				'counts' => $translation->countRules(),
				'notes' => $translation->listNotes(),
				'untranslated' => array_keys($translation->findUntranslated()),
			]);
			return 0;
		}

		if ($dryRun) {
			$this->context->out->write($text);
		}

		$printer = new MigrationPrinter($translation, $config->file, $dryRun ? null : $output, $target);
		$this->context->err->write(($choice->named ? '' : "\n") . $printer->print($this->context->err));
		return 0;
	}


	/**
	 * @param  list<string>  $paths
	 */
	#[Cli\Command('Rewrite the suppression comments of PHP_CodeSniffer to the DressCode form')]
	public function suppressions(
		ParseResult $args,
		#[Cli\Argument('Files or directories; the configured paths when omitted', optional: true, repeatable: true)]
		array $paths,
		ConfigOptions $configuration,
		#[Cli\Flag('Show the rewrites as a unified diff instead of writing them')]
		bool $dryRun = false,
		#[Cli\Option('Output format: `console`, or `json` for a tool or an AI agent', alias: '-f', enum: ['console', 'json'])]
		string $format = 'console',
	): int
	{
		// the comments need the files and the names of the decisions, not the rules, which may not run yet
		[$factory, $resolution] = $this->context->resolveProject($args, $configuration);
		$config = $resolution->config;
		$finder = $factory->createFileFinder($resolution);
		$named = array_map($this->context->resolvePath(...), $paths);
		$paths = $named ? $finder->narrowPaths($named, $config->paths) : $config->paths;
		if (!$paths) {
			throw new UsageException('No paths given and none configured.', $args->command);
		}

		$migration = new SuppressionMigration($factory->registry->expandSuppressedName(...));
		$parser = new Parser;
		$count = $files = 0;
		$unknown = $widened = $skipped = $diffs = [];
		foreach ($finder->findFiles($paths) as $path) {
			$absolute = $finder->toAbsolute($path);
			$code = @file_get_contents($absolute); // @ is escalated to exception
			if ($code === false) {
				throw new \RuntimeException("Cannot read file `$path`.");
			}

			try {
				$file = $parser->parse($code);
			} catch (ParseException $e) {
				$skipped[$path] = $e->getMessage();
				continue;
			}

			$rewrite = $migration->migrate($file);
			if ($rewrite->count > 0) {
				if ($dryRun) {
					$diffs[$path] = Diff::unified($code, Printer::print($file), $path);
				} elseif (@file_get_contents($absolute) !== $code) { // @ a file deleted meanwhile changed too
					$skipped[$path] = 'it changed while it was being migrated';
					continue;
				} elseif (!Helpers::writeFile($absolute, Printer::print($file))) {
					throw new \RuntimeException("Cannot write file `$path`.");
				}

				$count += $rewrite->count;
				$files++;
			}

			// what is told is what the files hold now, a file skipped saying nothing
			foreach ($rewrite->unknownNames as $name => $lines) {
				$unknown[$name] = [...$unknown[$name] ?? [], ...array_map(fn(int $line) => "$path:$line", $lines)];
			}

			array_push($widened, ...array_map(fn(int $line) => "$path:$line", $rewrite->widened));
		}

		// what the comments still need from a person is said, and only a file left unprocessed is a migration not done
		$exit = $skipped !== [] ? 2 : 0;
		if ($format === 'json') {
			$this->context->writeJson([
				'version' => self::Version,
				'root' => $resolution->root,
				'migrated' => $count,
				'files' => $files,
				'written' => !$dryRun,
				'diffs' => (object) $diffs,
				'unknown' => (object) $unknown,
				'widened' => $widened,
				'skipped' => (object) $skipped,
			]);
			return $exit;
		}

		$out = $this->context->out;
		foreach ($diffs as $diff) {
			$out->write(Markup::highlightDiff($out, $diff));
		}

		$out->write(new SuppressionsPrinter($count, $files, $dryRun, $unknown, $widened, $skipped)->print($out));
		return $exit;
	}
}
