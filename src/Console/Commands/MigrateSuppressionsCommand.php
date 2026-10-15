<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Console\{CommandContext, Markup};
use DressCode\Engine\{Helpers, SuppressionMigration};
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
use PhpSyntax\{ParseException, Parser, Printer};


/**
 * The command `migrate-suppressions`: rewrites the phpcs suppression comments of the files to the dresscode form and
 * writes them back.
 * @internal
 */
final readonly class MigrateSuppressionsCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	/**
	 * @param  list<string>  $paths
	 */
	#[Cli\Command('Rewrite the suppression comments of PHP_CodeSniffer to the DressCode form')]
	public function migrateSuppressions(
		ParseResult $args,
		#[Cli\Argument('Files or directories; the configured paths when omitted', optional: true, repeatable: true)]
		array $paths,
		ConfigOptions $configuration,
	): int
	{
		[$factory, $resolution] = $this->context->resolveProject($args, $configuration);
		$config = $resolution->config;
		$runner = $factory->createRunner($resolution, baseline: false);
		$named = array_map($this->context->resolvePath(...), $paths);
		$paths = $named ? $runner->narrowPaths($named, $config->paths) : $config->paths;
		if (!$paths) {
			throw new UsageException('No paths given and none configured.', $args->command);
		}

		$migration = new SuppressionMigration($factory->registry->expandSuppressedName(...));
		$parser = new Parser;
		$files = 0;
		foreach ($runner->findFiles($paths) as $path) {
			$absolute = $runner->toAbsolute($path);
			$code = @file_get_contents($absolute); // @ is escalated to exception
			if ($code === false) {
				throw new \RuntimeException("Cannot read file `$path`.");
			}

			try {
				$file = $parser->parse($code);
			} catch (ParseException $e) {
				$this->context->write("`$path`: skipped, {$e->getMessage()}\n");
				continue;
			}

			if ($migration->migrate($file)) {
				if (!Helpers::writeFile($absolute, Printer::print($file))) {
					throw new \RuntimeException("Cannot write file `$path`.");
				}

				$files++;
			}
		}

		$this->context->write('Migrated ' . Markup::formatCount($migration->count, 'suppression comment') . ' in ' . Markup::formatCount($files, 'file') . ".\n");
		if ($migration->unknownNames) {
			$this->context->write('Warning: The names DressCode does not know are kept as they are: `' . implode('`, `', array_keys($migration->unknownNames)) . "`.\n");
		}

		if ($migration->ownLineIgnore) {
			$this->context->write("Note: A `dresscode:ignore` on a line of its own covers the whole statement below it, not just the next line; review the migrated ones.\n");
		}

		return 0;
	}
}
