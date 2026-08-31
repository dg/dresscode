<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, RuleException};
use Nette\CommandLine\{ColorDepth, Command, Console, HelpRenderer, Parser as CommandLineParser, UsageException};
use Nette\Utils\FileSystem;
use function extension_loaded;


/**
 * The dresscode command line.
 */
final readonly class Application
{
	public const Version = '1.0-dev';

	private CommandContext $context;


	/**
	 * @param  ?resource  $stdout
	 * @param  ?resource  $stderr
	 * @param  ?resource  $stdin
	 * @param  ?Config  $defaultConfig  what applies when the project has no configuration file
	 * @param  ?bool  $xdebug  whether Xdebug is loaded; detected when the output is the process's own
	 */
	public function __construct(
		$stdout = null,
		$stderr = null,
		$stdin = null,
		?string $cwd = null,
		?Config $defaultConfig = null,
		?bool $xdebug = null,
	) {
		// what the caller hands in is captured output, which FORCE_COLOR must not color
		$out = new Console($stdout ?? STDOUT, colorDepth: $stdout === null ? null : ColorDepth::None);
		$err = new Console($stderr ?? STDERR, colorDepth: $stderr === null ? null : ColorDepth::None);
		$this->context = new CommandContext(
			stdout: $stdout ?? STDOUT,
			stderr: $stderr ?? STDERR,
			stdin: $stdin ?? STDIN,
			out: $out,
			err: $err,
			workingDirectory: $cwd ?? (string) getcwd(),
			defaultConfig: $defaultConfig,
			xdebug: $xdebug ?? ($stdout === null && extension_loaded('xdebug')),
		);
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations or syntax errors, 2 a file that
	 * failed, 3 a mistake of the command line or of the configuration.
	 * @param  list<string>  $argv  including the script name
	 */
	public function run(array $argv): int
	{
		[$context, $out, $err] = [$this->context, $this->context->out, $this->context->err];
		$program = $this->defineCommandLine();
		$command = $program;
		try {
			$args = (new CommandLineParser)->parse($program, array_slice($argv, 1));
			$command = $args->command;
			if ($args['--no-color']) {
				$out->setColorDepth(ColorDepth::None);
				$err->setColorDepth(ColorDepth::None);
			}

			if ($args['--version']) {
				$out->writeLine(CommandContext::formatName($out));
				return 0;
			} elseif ($args['--help'] || $command === $program) {
				$out->writeLine(CommandContext::formatName($out) . "\n");
				new HelpRenderer($out)->render($command);
				return $command === $program && !$args['--help'] ? 3 : 0;
			}

			return $args->run();

		} catch (UsageException $e) {
			new HelpRenderer($this->context->err)->renderError($e);
			return 3;

		} catch (ConfigurationException $e) {
			$context->writeError($e->getMessage(), $e->docs);
			return 3;

		} catch (RuleException|\RuntimeException $e) {
			$context->writeError($e->getMessage());
			return 2;

		} catch (ConvergenceException $e) {
			$context->writeError(
				$e->getMessage(),
				ConvergenceException::Docs,
				$e->diff === '' ? '' : "The two states differ:\n" . Markup::highlightDiff($err, $e->diff),
			);
			return 2;

		} catch (\Throwable $e) { // an error of the tool itself
			$context->writeError(
				$e->getMessage(),
				detail: get_debug_type($e)
					. ' in '
					. FileSystem::platformSlashes($e->getFile())
					. ":{$e->getLine()}\n"
					. $e->getTraceAsString()
					. "\n",
			);
			return 2;
		}
	}


	/**
	 * The commands of the tool, each with its handler; the options of the program apply to every one of them.
	 */
	private function defineCommandLine(): Command
	{
		$program = new Command('dresscode', 'A dress code for PHP: checks, fixes and upgrades the code.');
		$program->addOption(
			'--config',
			'Configuration file; the nearest `dresscode.neon` or `dresscode.php` when omitted',
			alias: '-c',
			valueName: 'file',
		);
		$program->addOption(
			'--use',
			'Add a preset or a plugin over the configuration, even without a configuration file',
			valueName: 'name',
			repeatable: true,
			normalizer: CommandContext::checkPreset(...),
		);
		$program->addOption(
			'--set',
			'Set a decision over the configuration as `path=value`, the value in NEON: `blankLines.betweenMethods=1`, `spacing.fnKeyword="fn ($x) => $x"`',
			valueName: 'path=value',
			repeatable: true,
			normalizer: CommandContext::checkDecision(...),
		);
		$program->addFlag('--no-color', 'Print plain output without colors');
		$program->addFlag('--help', 'Print this help', standalone: true);
		$program->addFlag('--version', 'Print the name and the version', standalone: true);

		$context = $this->context;
		foreach ([ // in the order the help lists them
			new Commands\CheckCommand($context),
			new Commands\ExplainCommand($context),
			new Commands\CatalogueCommand($context),
		] as $handlers) {
			$program->addCommandsFrom($handlers);
		}

		$program->addText('Exit codes: `0` clean, `1` violations or syntax errors, `2` a file failed, `3` a mistake of the command line or of the configuration.');
		return $program;
	}
}
