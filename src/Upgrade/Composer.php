<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\ProjectPackages;
use DressCode\Engine\ProcessRunner;
use Nette\Utils\FileSystem;


/**
 * Composer of the project: what its lock holds that refuses a target, which needs no network, what raising the
 * constraints would move, and the packages it installs. Composer is the script `COMPOSER_BINARY` names, run by this PHP
 * as Composer sets it for the scripts it runs, else `composer` on the path.
 * @internal
 */
final readonly class Composer
{
	/** how long Composer may answer before the answer is unknown */
	private const Timeout = 60;

	/** how long Composer may take to update the packages */
	private const UpdateTimeout = 1800;

	private const BlockerPattern = '~^(\S+)\s+(\S+)\s+(requires|conflicts)\s+(\S+)\s+\((.+)\)\s*$~';


	public function __construct(
		/** the directory of the composer.json and the lock Composer works with */
		private string $root,
		/** the package of the project itself, which never blocks its own upgrade */
		private ?string $rootName = null,
	) {
	}


	/** Composer of a project that has a lock, which the questions about the installed packages need; null for one without. */
	public static function forLockOf(ProjectPackages $project): ?self
	{
		return $project->rootPath !== null && is_file("$project->rootPath/composer.lock")
			? new self($project->rootPath, $project->rootName)
			: null;
	}


	/** The installed packages of the lock refusing the version of the target, as `composer why-not --locked` says. */
	public function findBlockers(Target $target): Blockers
	{
		if (!is_file("$this->root/composer.lock")) {
			return new Blockers($target, unknown: 'the project has no `composer.lock` to ask');
		}

		try {
			// why-not exits with 1 where something refuses the version
			$output = $this->run(['why-not', '--locked', $target->package, $target->version], self::Timeout, accepted: 1);
		} catch (ComposerException $e) {
			return new Blockers($target, unknown: $e->getMessage());
		}

		return self::parseBlockers($target, $output, $this->rootName);
	}


	/**
	 * The packages the output of `composer why-not` lists as refusing the version, the project itself left out; unknown
	 * where the output is no answer of why-not, which exits with 1 as a Composer that failed does.
	 * @internal
	 */
	public static function parseBlockers(Target $target, string $output, ?string $rootName): Blockers
	{
		$blockers = [];
		$answered = str_contains($output, 'There is no installed package depending on');
		foreach (preg_split('~\R~', $output) ?: [] as $line) {
			if (!preg_match(self::BlockerPattern, trim($line), $m)) {
				continue;
			}

			$answered = true;
			// the project and the target itself, whose versions refusing the PHP Composer runs on refuse no target
			if ($m[1] !== $rootName && $m[1] !== $target->package) {
				$blockers[] = new Blocker($m[1], $m[2], $m[3] === 'conflicts' ? "conflicts with $m[4] $m[5]" : $m[5]);
			}
		}

		return $answered
			? new Blockers($target, $blockers)
			: new Blockers($target, unknown: 'Composer gave no answer: ' . (self::findLastLine($output) ?? 'no output'));
	}


	/**
	 * What raising the constraints moves the installed packages to, as the dry run of the update says, which needs the
	 * network.
	 * @param  array<string, string>  $constraints  package => constraint
	 */
	public function findMoves(array $constraints): Moves
	{
		try {
			return new Moves(self::parseMoves($this->previewRequire($constraints)));
		} catch (ComposerException $e) {
			return new Moves(unknown: $e->getMessage());
		}
	}


	/**
	 * The packages the operations of an update move from one version to another.
	 * @param  list<string>  $operations  as `parseOperations()` reads them
	 * @return list<Move>
	 */
	public static function parseMoves(array $operations): array
	{
		$moves = [];
		foreach ($operations as $operation) {
			if (preg_match('~^- (?:Upgrading|Downgrading) (\S+) \((\S+) => (\S+)\)~', $operation, $m)) {
				$moves[] = new Move($m[1], $m[2], $m[3]);
			}
		}

		return $moves;
	}


	/**
	 * What the raise would do to the installed packages, changing nothing: the operations of the update.
	 * @param  array<string, string>  $constraints  package => constraint
	 * @return list<string>  as `- Upgrading nette/utils (v3.2.10 => v4.0.11)`
	 * @throws ComposerException
	 */
	public function previewRequire(array $constraints): array
	{
		return self::parseOperations($this->run(self::buildRequire($constraints, update: true, dryRun: true), self::UpdateTimeout));
	}


	/**
	 * Requires the packages at the versions Composer chooses and installs them, the way `composer require` does.
	 * @param  list<string>  $packages
	 * @throws ComposerException
	 */
	public function add(array $packages, bool $dev = false): void
	{
		$this->run(['require', ...$dev ? ['--dev'] : [], ...$packages], self::UpdateTimeout);
	}


	/**
	 * The operations on packages the output of an update lists, each once, though Composer writes it among the
	 * operations of the lock and again among those of the installation.
	 * @return list<string>  as `- Upgrading nette/utils (v3.2.10 => v4.0.11)`
	 */
	public static function parseOperations(string $output): array
	{
		$lines = array_map(trim(...), preg_split('~\R~', $output) ?: []);
		return array_values(array_unique(preg_grep('~^- (Upgrading|Downgrading|Installing|Removing) ~', $lines) ?: []));
	}


	/**
	 * The arguments of `composer require` writing the constraints, with the update or without it.
	 * @param  array<string, string>  $constraints
	 * @return list<string>
	 */
	public static function buildRequire(array $constraints, bool $update, bool $dryRun = false): array
	{
		return [
			'require',
			...array_map(fn(string $package, string $constraint) => "$package:$constraint", array_keys($constraints), $constraints),
			...$update ? ['-W', '--prefer-lowest', '--minimal-changes'] : ['--no-update'],
			...$dryRun ? ['--dry-run'] : [],
		];
	}


	/**
	 * What Composer wrote on both streams.
	 * @param  list<string>  $arguments
	 * @throws ComposerException  where it does not end in time or exits with a code above the one accepted
	 */
	private function run(array $arguments, int $timeout, int $accepted = 0): string
	{
		$binary = getenv('COMPOSER_BINARY');
		$command = [...$binary ? [PHP_BINARY, $binary] : ['composer'], ...$arguments, '--no-ansi', '--no-interaction'];
		[$exit, $output] = ProcessRunner::run($command, $this->root, $timeout);
		if ($exit === null || $exit > $accepted) {
			$log = $this->keepLog($command, $timeout, $exit, $output);
			throw new ComposerException(($exit === null
				? "Composer did not answer within $timeout s"
				: 'Composer failed: ' . (self::findLastLine($output) ?? "exit code $exit"))
				. ($log === null ? '' : "; its whole output is in `$log`"));
		}

		return $output;
	}


	/**
	 * Writes the command, its exit and all it wrote into a file of the temporary directory, as the message keeps only
	 * the last line; null where the file cannot be written.
	 * @param  list<string>  $command
	 */
	private function keepLog(array $command, int $timeout, ?int $exit, string $output): ?string
	{
		$file = sys_get_temp_dir() . '/dresscode-composer-' . date('Ymd-His') . '-' . substr(hash('xxh128', $output), 0, 8) . '.log';
		$head = 'command: ' . implode(' ', $command) . "\ndirectory: $this->root\n"
			. ($exit === null ? "timeout: $timeout s, not answered\n" : "exit code: $exit\n");
		return @file_put_contents($file, "$head\n$output") === false ? null : FileSystem::platformSlashes($file); // @ the temporary directory may refuse it
	}


	private static function findLastLine(string $output): ?string
	{
		$lines = array_filter(array_map(trim(...), preg_split('~\R~', $output) ?: []));
		return $lines === [] ? null : end($lines);
	}
}
