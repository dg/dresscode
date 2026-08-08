<?php declare(strict_types=1);

namespace DressCode\Console;

use DressCode\Config;
use DressCode\Config\ConfigLoader;
use DressCode\Config\EngineFactory;
use DressCode\ConfigurationException;
use DressCode\ConvergenceException;
use DressCode\Engine\Baseline;
use DressCode\Engine\RunResult;
use DressCode\Engine\SuppressionMigration;
use DressCode\Reporter;
use DressCode\Reporters;
use DressCode\RuleException;
use DressCode\RuleInfo;
use Nette\CommandLine\Console;
use Nette\CommandLine\Parser;
use PhpSyntax\ParseException;
use PhpSyntax\Printer;


/**
 * The dresscode command: check, fix and rules.
 */
final class Application
{
	public const Version = '1.0-dev';

	private const Help = <<<'XX'
		DressCode: PHP code style checker and fixer

		Usage:
		  dresscode check [paths...] [options]   report violations
		  dresscode fix [paths...] [options]     fix what the rules can and report the rest
		  dresscode rules [options]              list the known rules
		  dresscode migrate-suppressions [paths...] [options]
		                                         rewrite phpcs suppression comments to the dresscode form

		Options:
		  --config <file>           configuration file; the nearest dresscode.php when omitted
		  --preset <name>...        add a preset
		  --rule <spec>...          enable or disable a rule: name=on or name=off
		  --format <name>           console, json or checkstyle
		  --diff                    show the fixes as a unified diff (console reporter)
		  --stdin-path <path>       read the code from stdin as if it were the file at the path;
		                            fix writes the result to stdout
		  --generate-baseline       write the violations found into the configured baseline file
		                            instead of reporting them (check only)
		  --strict                  a rule breaking its contract is an error
		  --no-color                plain output
		  --version                 print the version
		  --help                    print this help

		Exit codes: 0 clean, 1 violations or syntax errors, 2 failure.

		XX;

	/** @var resource */
	private $stdout;

	/** @var resource */
	private $stderr;

	/** @var resource */
	private $stdin;

	private Console $console;


	/**
	 * @param ?resource $stdout
	 * @param ?resource $stderr
	 * @param ?resource $stdin
	 */
	public function __construct(
		$stdout = null,
		$stderr = null,
		$stdin = null,
		private readonly ?string $cwd = null,
	) {
		$this->stdout = $stdout ?? STDOUT;
		$this->stderr = $stderr ?? STDERR;
		$this->stdin = $stdin ?? STDIN;
		$this->console = new Console;
		$this->console->useColors($stdout === null && Console::detectColors());
	}


	/**
	 * Runs the command line and returns the exit code: 0 clean, 1 violations or syntax errors, 2 a failure.
	 * @param list<string> $argv  including the script name
	 */
	public function run(array $argv): int
	{
		try {
			$args = $this->parseArguments(array_slice($argv, 1));
			if ($args['--no-color']) {
				$this->console->useColors(false);
			}

			if ($args['--version']) {
				$this->write('DressCode ' . self::Version . "\n");
				return 0;
			} elseif ($args['--help'] || $args['command'] === null) {
				$this->write(self::Help);
				return $args['command'] === null && !$args['--help'] ? 2 : 0;
			}

			return match ($args['command']) {
				'check' => $this->runCheckOrFix($args, fix: false),
				'fix' => $this->runCheckOrFix($args, fix: true),
				'rules' => $this->runRules($args),
				'migrate-suppressions' => $this->runMigrateSuppressions($args),
				default => throw new UsageException("Unknown command '{$args['command']}'."),
			};

		} catch (UsageException $e) {
			$this->writeError("Error: {$e->getMessage()}\n\n" . self::Help);
			return 2;

		} catch (ConfigurationException | RuleException | \RuntimeException $e) {
			$this->writeError("Error: {$e->getMessage()}\n");
			return 2;

		} catch (ConvergenceException $e) {
			$this->writeError("Error: {$e->getMessage()}\n" . ($e->diff === '' ? '' : "The two states differ:\n$e->diff"));
			return 2;
		}
	}


	/**
	 * @param  list<string>  $args
	 * @return array<string, mixed>
	 * @throws UsageException
	 */
	private function parseArguments(array $args): array
	{
		$parser = (new Parser(self::Help, ['--format' => [Parser::Enum => ['console', 'json', 'checkstyle']]]))
			->addArgument('command', optional: true)
			->addArgument('paths', optional: true, repeatable: true);
		try {
			return $parser->parse($args);
		} catch (\Throwable $e) {
			throw new UsageException($e->getMessage(), previous: $e);
		}
	}


	/** @param array<string, mixed> $args */
	private function runCheckOrFix(array $args, bool $fix): int
	{
		$factory = new EngineFactory;
		[$config, $root] = $this->loadConfig($args);
		$engine = $factory->createEngine($config, $root, strict: (bool) $args['--strict']);
		$stdinPath = $args['--stdin-path'];
		$paths = $args['paths'];

		if (is_string($stdinPath)) {
			if ($paths) {
				throw new UsageException('Paths cannot be combined with --stdin-path.');
			}

			$reporter = $this->createReporter($args, $fix ? $this->stderr : $this->stdout);
			$code = (string) stream_get_contents($this->stdin);
			$reporter->start(1, $fix);
			$result = $engine->processFile($stdinPath, $code);
			$reporter->reportFile($result);
			$run = new RunResult([$result], $fix);
			$reporter->finish($run);
			if ($fix) {
				$this->write($result->output ?? $code);
			}

			return $run->getExitCode();
		}

		$paths = $paths ?: $config->getPaths();
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		if ($args['--generate-baseline']) {
			return $this->generateBaseline($factory, $config, $root, $paths, $fix);
		}

		$reporter = $this->createReporter($args, $this->stdout);
		return $engine->run($paths, $fix, $reporter)->getExitCode();
	}


	/**
	 * Runs the check without the current baseline and writes what it found into the configured baseline file.
	 * @param  list<string>  $paths
	 */
	private function generateBaseline(
		EngineFactory $factory,
		Config $config,
		string $root,
		array $paths,
		bool $fix,
	): int
	{
		$name = $config->getBaseline();
		$file = EngineFactory::resolveBaselineFile($config, $root);
		if ($fix) {
			throw new UsageException('The baseline is generated by check, not by fix.');
		} elseif ($name === null || $file === null) {
			throw new UsageException('Configure the baseline file with Config::baseline() first.');
		}

		$engine = $factory->createEngine($config->baseline(null), $root);
		$run = $engine->run($paths, fix: false, reporter: new Reporters\NullReporter);
		$baseline = Baseline::fromResults($run->files);
		$baseline->save($file);
		$this->write(sprintf("Baseline with %d violation%s written to %s.\n", $baseline->count(), $baseline->count() === 1 ? '' : 's', $name));
		return $run->countFailures() || $run->countErrors() ? 2 : 0;
	}


	/**
	 * Rewrites the phpcs suppression comments of the files to the dresscode form and writes them back.
	 * @param array<string, mixed> $args
	 */
	private function runMigrateSuppressions(array $args): int
	{
		$factory = new EngineFactory;
		[$config, $root] = $this->loadConfig($args);
		$engine = $factory->createEngine($config, $root);
		$paths = $args['paths'] ?: $config->getPaths();
		if (!$paths) {
			throw new UsageException('No paths given and none configured.');
		}

		$migration = new SuppressionMigration($factory->getRegistry()->resolveName(...));
		$parser = new \PhpSyntax\Parser\Parser;
		$files = 0;
		foreach ($engine->findFiles($paths) as $path) {
			$absolute = $engine->toAbsolute($path);
			$code = @file_get_contents($absolute); // @ - reported as exception
			if ($code === false) {
				throw new \RuntimeException("Cannot read file $path.");
			}

			try {
				$file = $parser->parse($code);
			} catch (ParseException $e) {
				$this->write("$path: skipped, {$e->getMessage()}\n");
				continue;
			}

			if ($migration->migrate($file)) {
				if (@file_put_contents($absolute, Printer::print($file)) === false) { // @ - reported as exception
					throw new \RuntimeException("Cannot write file $path.");
				}

				$files++;
			}
		}

		$this->write(sprintf("Migrated %d suppression comment%s in %d file%s.\n", $migration->count, $migration->count === 1 ? '' : 's', $files, $files === 1 ? '' : 's'));
		if ($migration->unknownNames) {
			$this->write('Warning: unknown rule names kept as they are: ' . implode(', ', array_keys($migration->unknownNames)) . "\n");
		}

		if ($migration->ownLineIgnore) {
			$this->write("Note: dresscode:ignore on a line of its own covers the whole statement below it, not just the next line; review the migrated ones.\n");
		}

		return 0;
	}


	/** @param array<string, mixed> $args */
	private function runRules(array $args): int
	{
		$factory = new EngineFactory;
		[$config, $root] = $this->loadConfig($args);
		$engine = $factory->createEngine($config, $root);
		$enabled = [];
		foreach ($engine->getProcessor()->getRules() as $rule) {
			$enabled[RuleInfo::of($rule)->name] = true;
		}

		$rules = $factory->getRegistry()->getRules();
		ksort($rules, SORT_STRING);
		foreach ($rules as $name => $class) {
			$info = RuleInfo::of($class);
			$this->write(sprintf(
				"%s %-45s %-10s %s%s\n",
				isset($enabled[$name]) ? '*' : ' ',
				$this->console->color(isset($enabled[$name]) ? 'white' : null, $name),
				$info->stage->name,
				$info->description,
				$info->aliases ? $this->console->color('gray', '  (' . implode(', ', $info->aliases) . ')') : '',
			));
		}

		$this->write("\n* enabled by the configuration\n");
		return 0;
	}


	/**
	 * The configuration with the command line layered over it, and the root directory.
	 * @param  array<string, mixed>  $args
	 * @return array{Config, string}
	 */
	private function loadConfig(array $args): array
	{
		$presets = $args['--preset'];
		[$config, $root] = (new ConfigLoader)->load(
			$args['--config'],
			$this->cwd ?? (string) getcwd(),
			defaultPreset: !$presets,
		);
		foreach ($presets as $preset) {
			$config->preset($preset);
		}

		foreach ($args['--rule'] as $rule) {
			if (!preg_match('~^(.+)=(on|off)$~D', $rule, $m)) {
				throw new UsageException("Option --rule expects name=on or name=off, '$rule' given.");
			}

			$m[2] === 'on' ? $config->enable($m[1]) : $config->disable($m[1]);
		}

		return [$config, $root];
	}


	/**
	 * @param array<string, mixed> $args
	 * @param resource $stream
	 */
	private function createReporter(array $args, $stream): Reporter
	{
		return match ($args['--format'] ?? 'console') {
			'json' => new Reporters\JsonReporter($stream),
			'checkstyle' => new Reporters\CheckstyleReporter($stream),
			default => new Reporters\ConsoleReporter($stream, diff: (bool) $args['--diff'], console: $this->console),
		};
	}


	public static function getHelp(): string
	{
		return self::Help;
	}


	private function write(string $text): void
	{
		fwrite($this->stdout, $text);
	}


	private function writeError(string $text): void
	{
		fwrite($this->stderr, $text);
	}
}
