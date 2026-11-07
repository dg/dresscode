<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\{Config, ConfigurationException, ConvergenceException, RuleException};
use DressCode\Engine\WorkerPool;
use Nette\CommandLine\{ColorDepth, Command, Console, HelpRenderer, Parser as CommandLineParser, Prompt, UsageException};
use Nette\Utils\FileSystem;
use function extension_loaded;
use const PHP_OS_FAMILY;


/**
 * The dresscode command line.
 */
final readonly class Application
{
	public const Version = '1.5-dev';

	private CommandContext $context;


	/**
	 * @param  ?resource  $stdout
	 * @param  ?resource  $stderr
	 * @param  ?resource  $stdin
	 * @param  ?string  $script  the script the workers and a run started again are started with, the program of this
	 *   package by default; a command of its own giving `$defaultConfig` names its own, which carries that configuration
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
			scriptFile: $script ?? ($defaultConfig === null
				? WorkerPool::locateProgram()
				: throw new \InvalidArgumentException('A command of its own giving a default configuration names its script, `script: __FILE__`, which its workers start so that they have that configuration too.')),
			defaultConfig: $defaultConfig,
			xdebug: $xdebug ?? ($stdout === null && extension_loaded('xdebug')),
			prompt: new Prompt($out, $stdin ?? STDIN, inputTerminal: $interactive),
		);
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean and complete, 1 violations, syntax errors, a refused
	 * baseline or work a migration leaves for the user, 2 a file that failed or a command that could not look at
	 * everything it was to, 3 a mistake of the command line or of the configuration, 4 an upgrade that installed its
	 * upgrading data and has to be run again.
	 * @param  list<string>  $argv  including the script name
	 */
	public function run(array $argv): int
	{
		// on Windows the processes share the memory of OPcache, and PHP with JIT crashes once it is full
		if (PHP_OS_FAMILY === 'Windows') {
			ini_set('opcache.enable', '0');
		}

		[$context, $out, $err] = [$this->context, $this->context->out, $this->context->err];
		$context->seekOutputEnd();
		try {
			$args = (new CommandLineParser)->parse($this->defineCommandLine(), array_slice($argv, 1));
			if ($args->get('--no-color')) {
				$out->setColorDepth(ColorDepth::None);
				$err->setColorDepth(ColorDepth::None);
			}

			if ($args->get('--version')) {
				$out->writeLine(CommandContext::formatName($out));
				return 0;
			} elseif ($args->get('--help')) {
				$out->writeLine(CommandContext::formatName($out) . "\n");
				new HelpRenderer($out)->render($args->command);
				return 0;
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
		$program = new Command('dresscode', 'A dress code for PHP: checks, fixes and upgrades the code.', commandRequired: true);
		$program->addFlag('--no-color', 'Print plain output without colors');
		$program->addFlag('--help', 'Print this help', standalone: true);
		$program->addFlag('--version', 'Print the name and the version', standalone: true);

		// in the order the help lists them
		$context = $this->context;
		$program->addCommandsFrom(new Commands\CheckCommand($context));
		$upgrade = $program->addCommand(
			'upgrade',
			'Upgrade the code to newer PHP and libraries',
			commandRequired: true,
		);
		$upgrade->addCommandsFrom(new Commands\UpgradeCommand($context));
		$upgrade->addText(implode("\n", [
			'Examples:',
			'  dresscode upgrade estimate php 8.4                what an upgrade to PHP 8.4 changes',
			'  dresscode upgrade today                           the code rewritten for the versions required now',
		]));
		foreach ([
			new Commands\ConfigCommand($context),
			new Commands\ExplainCommand($context),
			new Commands\CatalogueCommand($context),
			new Commands\InitCommand($context),
		] as $handlers) {
			$program->addCommandsFrom($handlers);
		}

		$program->addCommand('migrate', 'Move over from PHP CS Fixer, PHP_CodeSniffer or Laravel Pint', commandRequired: true)
			->addCommandsFrom(new Commands\MigrateCommand($context));

		$program->addText('Exit codes: `0` clean and complete, `1` violations, syntax errors, a refused baseline or manual tasks a migration leaves, `2` a file failed or a command could not look at everything it was to, `3` a mistake of the command line or of the configuration, `4` an upgrade that installed its upgrading data and has to be run again.');
		return $program;
	}
}
