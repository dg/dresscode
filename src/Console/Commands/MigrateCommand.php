<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\{ConfigResolver, PluginRegistry};
use DressCode\Console\{CommandContext, Markup};
use DressCode\Engine\{Helpers, SuppressionMigration};
use DressCode\Interop\{FormatterConfig, PhpCodeSniffer};
use DressCode\Measuring\Scope;
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use PhpSyntax\{ParseException, Parser, Printer};
use function count, in_array, sprintf;


/**
 * The commands of `migrate`, the move from another formatter: `config` translates a configuration of PHP CS Fixer,
 * PHP_CodeSniffer or Laravel Pint, the one named or the one the project has, and writes it as NEON or PHP by the
 * extension of the file, what it could not translate said on the error output; a configuration of PHP CS Fixer is
 * PHP, which reading runs, so one the project has is read only after the user agrees. `suppressions` rewrites the
 * phpcs suppression comments of the files to the dresscode form and writes them back.
 * @internal
 */
final readonly class MigrateCommand
{
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
	): int
	{
		$target = $this->context->resolvePath($output);
		$format = strtolower(pathinfo($target, PATHINFO_EXTENSION));
		if (!in_array($format, ['neon', 'php'], true)) {
			throw new UsageException(
				"The configuration `$output` is written as NEON or PHP, so its extension is `.neon` or `.php`.",
				$args->command,
			);
		} elseif (file_exists($target)) {
			throw new UsageException("The configuration `$output` exists already; remove it, or name another file.", $args->command);
		}

		$root = Helpers::canonicalizePath($this->context->workingDirectory);
		$config = $file === null
			? $this->findConfig($root, $args)
			: FormatterConfig::fromFile($root, $this->context->resolvePath($file));
		if ($config === null) {
			return 0;
		}

		$translation = $config->translate();
		// the scope as init writes it, and the decisions a rule of the formatter stands for kept where it leaves files out
		$scope = Scope::fromProject($root, $config);
		$draft = $translation->toDraft()->setScope($scope->listPaths(), $scope->listExcludePaths(), $scope->listExtensions());
		$catalogue = new ConfigResolver(new PluginRegistry)->getCatalogue();
		$config->addOverrides($draft, $scope, fn(string $path) => $catalogue->find($path)?->takesKeep() ?? false);
		FileSystem::write($target, $format === 'neon' ? $draft->toNeon() : $draft->toPhp());
		$this->context->writeNote("Written `$output`.\n");

		$this->context->writeNote(sprintf(
			"\nRead %s; set %s and %s.\n",
			Markup::formatCount($translation->ruleCount, 'rule'),
			Markup::formatCount(count($translation->getPaths()), 'decision'),
			Markup::formatCount(count($translation->presets), 'preset'),
		));
		foreach ($translation->warnings as $warning) {
			$this->context->writeNote("  $warning\n");
		}

		$untranslated = array_diff_key(
			['indentation.unit' => 'the indentation', 'file.lineEnding' => 'the line ending'],
			array_flip($translation->getPaths()),
		);
		if (!$translation->presets && $untranslated) {
			$this->context->writeNote(sprintf(
				"  %s %s not translated; set %s with `%s`.\n",
				ucfirst(implode(' and ', $untranslated)),
				count($untranslated) > 1 ? 'are' : 'is',
				count($untranslated) > 1 ? 'them' : 'it',
				implode('` and `', array_keys($untranslated)),
			));
		}

		$hint = self::formatSuppressionsHint(PhpCodeSniffer::countSuppressions($root, $scope->files));
		if ($hint !== '') {
			$this->context->writeNote("\n$hint.\n");
		}

		return 0;
	}


	/**
	 * The configuration of the formatter the project has, the one a script of `composer.json` runs first; a file of
	 * PHP CS Fixer only where the user agrees to run it, null where they do not.
	 */
	private function findConfig(string $root, ParseResult $args): ?FormatterConfig
	{
		$config = FormatterConfig::find($root) ?? throw new UsageException(
			'The project has no configuration of PHP CS Fixer, PHP_CodeSniffer or Laravel Pint; name the file to translate.',
			$args->command,
		);
		$this->context->writeNote(
			"Found `$config->file` of {$config->formatter->value}"
			. ($config->runBy === null ? '' : ", run by `$config->runBy`")
			. ".\n",
		);
		if (!$config->formatter->runsCode()) {
			return $config;
		} elseif (!$this->context->prompt->isInteractive()) {
			throw new UsageException(
				"`$config->file` is PHP, which reading runs, so it is read only when named: `dresscode migrate config $config->file`.",
				$args->command,
			);
		} elseif ($this->context->prompt->choose("`$config->file` is PHP, which reading runs. Read it?", ['y', 'n'], 'n') !== 'y') {
			$this->context->writeNote("Nothing written.\n");
			return null;
		}

		return $config;
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


	/** The line pointing a project at the phpcs suppression comments it has, empty where it has none. */
	public static function formatSuppressionsHint(int $count): string
	{
		return $count
			? Markup::formatCount($count, 'phpcs suppression comment') . ': `dresscode migrate suppressions` rewrites them'
			: '';
	}
}
