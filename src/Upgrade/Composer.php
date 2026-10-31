<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\ProjectPackages;


/**
 * Composer of the project: what its lock holds that refuses a target, which needs no network, what raising the
 * constraints would move, and the raise itself. Composer is the command `COMPOSER_BINARY` names, else `composer` on
 * the path.
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
		/** the directory of the composer.json and the lock of the project */
		private string $root,
		/** the package of the project itself, whose own constraint is the one an upgrade raises */
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
		try {
			// why-not exits with 1 where something refuses the version
			$output = $this->run(['why-not', '--locked', $target->package, $target->version], self::Timeout, accepted: 1);
		} catch (ComposerException $e) {
			return new Blockers($target, unknown: $e->getMessage());
		}

		return self::parseBlockers($target, $output, $this->rootName);
	}


	/**
	 * The packages the output of `composer why-not` lists as refusing the version, the project itself left out.
	 * @internal
	 */
	public static function parseBlockers(Target $target, string $output, ?string $rootName): Blockers
	{
		$blockers = [];
		foreach (preg_split('~\R~', $output) ?: [] as $line) {
			// the project and the target itself, whose versions refusing the PHP Composer runs on refuse no target
			if (preg_match(self::BlockerPattern, trim($line), $m) && $m[1] !== $rootName && $m[1] !== $target->package) {
				$blockers[] = new Blocker($m[1], $m[2], $m[3] === 'conflicts' ? "conflicts with $m[4] $m[5]" : $m[5]);
			}
		}

		return new Blockers($target, $blockers);
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
	 * What the raise would do to the installed packages, changing nothing: the operations of the update, none without
	 * `$update`.
	 * @param  array<string, string>  $constraints  package => constraint
	 * @return list<string>  as `- Upgrading nette/utils (v3.2.10 => v4.0.11)`
	 * @throws ComposerException
	 */
	public function previewRequire(array $constraints, bool $update = true): array
	{
		return self::parseOperations($this->run(self::buildRequire($constraints, $update, dryRun: true), self::UpdateTimeout));
	}


	/**
	 * Writes the constraints into `composer.json` and updates the packages they move to the lowest versions they allow,
	 * which the code is written for and whose `@deprecated` the run reads, those they depend on changed as little as
	 * the update needs; without `$update` only `composer.json` changes.
	 * @param  array<string, string>  $constraints  package => constraint
	 * @throws ComposerException
	 */
	public function require(array $constraints, bool $update = true): void
	{
		$this->run(self::buildRequire($constraints, $update, dryRun: false), self::UpdateTimeout);
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
	 * @param  array<string, string>  $constraints
	 * @return list<string>
	 */
	private static function buildRequire(array $constraints, bool $update, bool $dryRun): array
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
		[$exit, $output] = ProcessRunner::run(
			[getenv('COMPOSER_BINARY') ?: 'composer', ...$arguments, '--no-ansi', '--no-interaction'],
			$this->root,
			$timeout,
		);
		if ($exit === null) {
			throw new ComposerException("Composer did not answer within $timeout s");
		} elseif ($exit > $accepted) {
			$lines = array_filter(array_map(trim(...), preg_split('~\R~', $output) ?: []));
			throw new ComposerException('Composer failed: ' . (end($lines) ?: "exit code $exit"));
		}

		return $output;
	}
}
