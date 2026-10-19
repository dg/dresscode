<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, RuleException};
use Nette\CommandLine\{ColorDepth, Command, Console, Prompt, Runner};
use Nette\Utils\FileSystem;
use function extension_loaded;


/**
 * The dresscode command line.
 */
final readonly class Application
{
	public const Version = '1.3.0';

	private CommandContext $context;


	/**
	 * @param  ?resource  $stdout
	 * @param  ?resource  $stderr
	 * @param  ?resource  $stdin
	 * @param  ?string  $script  the script the workers are started with, the one the process was started with by default
	 * @param  ?Config  $defaultConfig  what applies when the project has no configuration file
	 * @param  ?bool  $xdebug  whether Xdebug is loaded; detected when the output is the process's own
	 * @param  ?bool  $interactive  whether the output and the input are a terminal a person answers at; detected from each
	 */
	public function __construct(
		$stdout = null,
		$stderr = null,
		$stdin = null,
		?string $cwd = null,
		?string $script = null,
		?Config $defaultConfig = null,
		?bool $xdebug = null,
		?bool $interactive = null,
	) {
		// what the caller hands in is captured output, which FORCE_COLOR must not color
		$out = new Console($stdout ?? STDOUT, colorDepth: $stdout === null ? null : ColorDepth::None, terminal: $interactive);
		$err = new Console($stderr ?? STDERR, colorDepth: $stderr === null ? null : ColorDepth::None);
		$this->context = new CommandContext(
			stdout: $stdout ?? STDOUT,
			stderr: $stderr ?? STDERR,
			stdin: $stdin ?? STDIN,
			out: $out,
			err: $err,
			workingDirectory: $cwd ?? (string) getcwd(),
			scriptFile: $script ?? $_SERVER['argv'][0] ?? 'dresscode',
			defaultConfig: $defaultConfig,
			xdebug: $xdebug ?? ($stdout === null && extension_loaded('xdebug')),
			prompt: new Prompt($out, $stdin ?? STDIN, inputTerminal: $interactive),
		);
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations, syntax errors or a refused
	 * baseline, 2 a file that failed, 3 a mistake of the command line or of the configuration.
	 * @param  list<string>  $argv  including the script name
	 */
	public function run(array $argv): int
	{
		[$context, $err] = [$this->context, $this->context->err];
		try {
			$runner = new Runner(
				$this->defineCommandLine(),
				version: CommandContext::formatName(...),
				colorFlag: true,
				usageExitCode: 3,
				out: $context->out,
				err: $err,
			);
			return $runner->run(array_slice($argv, 1));

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
		$program = new Command('dresscode', 'A dress code for PHP: checks, fixes and upgrades the code.', commandRequired: true);

		$context = $this->context;
		foreach ([ // in the order the help lists them
			new Commands\CheckCommand($context),
			new Commands\ConfigCommand($context),
			new Commands\ExplainCommand($context),
			new Commands\CatalogueCommand($context),
			new Commands\InitCommand($context),
			new Commands\ImportCommand($context),
			new Commands\MigrateSuppressionsCommand($context),
		] as $handlers) {
			$program->addCommandsFrom($handlers);
		}

		$program->addText('Exit codes: `0` clean, `1` violations, syntax errors or a refused baseline, `2` a file failed, `3` a mistake of the command line or of the configuration.');
		return $program;
	}
}
