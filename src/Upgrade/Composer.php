<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;


/**
 * Composer of the project: what its lock holds that refuses a target, which needs no network, and the constraints an
 * upgrade raises. Composer is the command `COMPOSER_BINARY` names, else `composer` on the path.
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


	/** The installed packages of the lock refusing the version of the target, as `composer why-not --locked` says. */
	public function findBlockers(Target $target): Blockers
	{
		[$exit, $output] = $this->run(['why-not', '--locked', $target->package, $target->version], self::Timeout);
		return match (true) {
			$exit === null => new Blockers($target, unknown: 'Composer did not answer within ' . self::Timeout . ' s'),
			$exit > 1 => new Blockers($target, unknown: 'Composer failed: ' . strtok(trim($output), "\n")),
			default => self::parseBlockers($target, $output, $this->rootName),
		};
	}


	/**
	 * The packages the output of `composer why-not` lists as refusing the version, the project itself left out.
	 * @internal
	 */
	public static function parseBlockers(Target $target, string $output, ?string $rootName): Blockers
	{
		$blockers = [];
		foreach (preg_split('~\R~', $output) ?: [] as $line) {
			if (preg_match(self::BlockerPattern, trim($line), $m) && $m[1] !== $rootName) {
				$blockers[] = new Blocker($m[1], $m[2], $m[3] === 'conflicts' ? "conflicts with $m[4] $m[5]" : $m[5]);
			}
		}

		return new Blockers($target, $blockers);
	}


	/**
	 * Writes the constraints into `composer.json` and updates the packages they move, with `-W` those they depend on too;
	 * without `$update` only `composer.json` changes, and a dry run changes nothing and says what the update would.
	 * @param  array<string, string>  $constraints  package => constraint
	 * @return array{?int, string}  the exit code, null where Composer did not end in time, and what it wrote
	 */
	public function requirePackages(array $constraints, bool $update = true, bool $dryRun = false): array
	{
		return $this->run([
			'require',
			'-W',
			...array_map(fn(string $package, string $constraint) => "$package:$constraint", array_keys($constraints), $constraints),
			...$update ? [] : ['--no-update'],
			...$dryRun ? ['--dry-run'] : [],
		], self::UpdateTimeout);
	}


	/**
	 * @param  list<string>  $arguments
	 * @return array{?int, string}
	 */
	private function run(array $arguments, int $timeout): array
	{
		return ProcessRunner::run(
			[getenv('COMPOSER_BINARY') ?: 'composer', ...$arguments, '--no-ansi', '--no-interaction'],
			$this->root,
			$timeout,
		);
	}
}
