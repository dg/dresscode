<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Console\{CommandContext, Markup};
use DressCode\Engine\Helpers;
use DressCode\Interop\FormatterConfig;
use Nette\CommandLine\{Attributes as Cli, ParseResult, UsageException};
use Nette\Utils\FileSystem;
use function count, in_array, sprintf;


/**
 * The command `migrate config`: translates the configuration of PHP CS Fixer or PHP_CodeSniffer in the file named and
 * writes it as NEON or PHP by the extension of the file, what it could not translate said on the error output.
 * @internal
 */
final readonly class MigrateCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	#[Cli\Command('Translate a PHP CS Fixer or PHP_CodeSniffer configuration')]
	public function config(
		ParseResult $args,
		#[Cli\Argument('Configuration file of PHP CS Fixer or PHP_CodeSniffer')]
		string $file,
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
		$config = FormatterConfig::fromFile($root, $this->context->resolvePath($file));

		$translation = $config->translate();
		$draft = $translation->toDraft();
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

		return 0;
	}
}
