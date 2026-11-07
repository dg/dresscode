<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\Loader;
use DressCode\Console\{Application, CommandContext, MigrationPrinter};
use DressCode\Engine\Helpers;
use DressCode\Interop\FormatterCandidate;
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use function count, in_array, sprintf;


/**
 * The command of `migrate`, the move from another formatter: `config` translates a configuration of PHP CS Fixer,
 * PHP_CodeSniffer or Laravel Pint into NEON or PHP by the extension of the file, checked as a run reads it before it
 * is written, what was not carried over being said in the file and no failure; a configuration of PHP CS Fixer is PHP,
 * which runs when it is read, so one the project has is read only after the user agrees. It shows what it would write
 * with `--dry-run`.
 * @internal
 */
final readonly class MigrateCommand
{
	/** the version of the JSON document, a key removed, renamed or meaning another thing being a new one */
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
		$written = count($translation->collectOwnDecisions());
		$text = $translation->toDraft(sprintf(
			"Translated from `%s` of %s\nby `dresscode migrate config` of DressCode %s.",
			FileSystem::isAbsolute($config->file) ? basename($config->file) : $config->file,
			$config->formatter->value,
			Application::Version,
		))->renderChecked($root, $syntax);
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
}
