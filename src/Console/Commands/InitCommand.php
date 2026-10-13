<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Config\{Loader, PluginRegistry};
use DressCode\Console\{CommandContext, InitPrinter, Markup};
use DressCode\Engine\Helpers;
use DressCode\Measuring\Proposal;
use Nette\CommandLine\Attributes as Cli;
use Nette\Utils\FileSystem;
use function count;


/**
 * The command `init`: measures how the project writes what can be measured and writes dresscode.neon with it; a
 * configuration that exists is never overwritten, the proposal is printed instead and the exit code says so.
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
	 */
	#[Cli\Command('Measure the code of the project and write a `dresscode.neon` to fit')]
	public function init(
		#[Cli\Option(
			'The standard and the sets to write, `perCs` by default',
			valueName: 'name',
			repeatable: true,
			normalizer: [ConfigOptions::class, 'checkPreset'],
		)]
		array $use = [],
	): int
	{
		$root = Helpers::canonicalizePath($this->context->workingDirectory);
		$presets = $use ?: null;
		$registry = new PluginRegistry;
		foreach ($presets ?? [] as $preset) {
			$registry->resolvePreset($preset); // a misspelled one before the measuring, not after it
		}

		$proposal = Proposal::measure($root, $presets);
		$proposal->verify($root);
		$neon = $proposal->toNeon();

		$existing = Loader::listFiles($root);
		$console = $existing ? $this->context->err : $this->context->out;
		$printer = new InitPrinter($proposal);
		$console->write(Markup::highlightCode(
			$console,
			CommandContext::formatName($console) . "\n" . $printer->printMeasurements($console),
		));
		$console->write(Markup::highlightCode($console, $printer->printDryRuns($console)));
		if ($existing) {
			$this->context->writeNote('`' . implode('` and `', $existing) . '`' . (count($existing) > 1 ? ' exist' : ' exists') . ", so the proposal is printed and nothing is written.\n");
			$this->context->out->write($neon);
			return 3;
		}

		FileSystem::write("$root/dresscode.neon", $neon);
		$this->context->write("\n`dresscode.neon` written, made to measure.\n");
		return 0;
	}
}
