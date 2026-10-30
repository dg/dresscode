<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use Composer\InstalledVersions;
use DressCode\Config\{ComposerJson, PackageDiscovery, ProjectPackages, Versions};
use DressCode\Console\Application;
use DressCode\Tristate;


/**
 * Orders an estimate into the steps of an upgrade: what refuses the targets, for a human; the fixes that work on the
 * versions allowed today, the bridge; the constraints raised; the fixes that need the targets, the jump; the code left
 * for the versions given up; and what a newer PHP offers, which is optional and left out until the user takes it in.
 * A library keeps the versions it allows, so its plan is the bridge and the constraints widened to the targets.
 * @internal
 */
final readonly class PlanBuilder
{
	public function __construct(
		private ProjectPackages $project,
		private bool $library = false,
	) {
	}


	public function build(Estimate $estimate, ?string $testCommand = null, ?string $quickTestCommand = null): Plan
	{
		$steps = [];
		foreach ($estimate->blockers as $blockers) {
			if ($blockers->packages !== []) {
				$steps[] = new Step(
					StepKind::Manual,
					'make way for '
					. $blockers->target->format()
					. ': '
					. implode(', ', array_map(fn(Blocker $blocker) => $blocker->package, $blockers->packages)),
					suggest: "composer why-not {$blockers->target->package} {$blockers->target->version}",
				);
			}
		}

		$steps[] = self::createFix('works on the versions allowed today', $estimate->findTopics(Phase::Bridge, 'deprecations'));
		$require = $this->library ? $this->widenConstraints($estimate->targets) : $this->raiseConstraints($estimate->targets);
		if ($require !== []) {
			$steps[] = new Step(StepKind::Composer, ($this->library ? 'widen ' : 'raise ') . implode(', ', array_map(
				fn(string $package, string $constraint) => "$package to $constraint",
				array_keys($require),
				$require,
			)), require: $require, update: !$this->library);
		}

		if (!$this->library) {
			$steps[] = self::createFix('needs the targets', $estimate->findTopics(Phase::Jump, 'deprecations'));
			$steps[] = self::createFix('code for the versions given up', [
				...$estimate->findTopics(Phase::Bridge, 'cleanup'),
				...$estimate->findTopics(Phase::Jump, 'cleanup'),
			]);
		}

		$steps[] = self::createFix('what the targets offer', [
			...$estimate->findTopics(Phase::Bridge, 'modernizations'),
			...$this->library ? [] : $estimate->findTopics(Phase::Jump, 'modernizations'),
		], skip: true);

		return new Plan(
			$estimate->targets,
			array_values(array_filter($steps)),
			Application::Version,
			self::findDataVersions($this->project),
			$testCommand,
			$quickTestCommand,
		);
	}


	/**
	 * Whether the project is a library, which says so by its `type` and has no lock of its own; maybe for one whose
	 * `type` says so while it has a lock, as an application does.
	 */
	public static function isLibrary(string $root): Tristate
	{
		$file = ComposerJson::findFile($root);
		return match (true) {
			(ComposerJson::read($file)['type'] ?? null) !== 'library' => Tristate::No,
			is_file(dirname((string) $file) . '/composer.lock') => Tristate::Maybe,
			default => Tristate::Yes,
		};
	}


	/** @param  list<Topic>  $topics */
	private static function createFix(string $title, array $topics, bool $skip = false): ?Step
	{
		$only = array_values(array_unique(array_map(fn(Topic $topic) => $topic->name, $topics)));
		return $only === [] ? null : new Step(StepKind::Fix, $title, $only, skip: $skip);
	}


	/**
	 * The constraints of `composer.json` the targets raise, each in the shape the project writes it: `^8.4` for `^8.1`,
	 * `>=8.4` for `>=8.1`; nothing for a target the lowest version allowed today already reaches.
	 * @param  list<Target>  $targets
	 * @return array<string, string>
	 */
	private function raiseConstraints(array $targets): array
	{
		$require = [];
		foreach ($targets as $target) {
			$constraint = $this->project->required[$target->package] ?? null;
			$lowest = $constraint === null ? null : Versions::findLowestVersion($constraint);
			if ($lowest === null || version_compare($lowest, $target->version, '<')) {
				$require[$target->package] = (str_starts_with(trim((string) $constraint), '>=') ? '>=' : '^') . $target->version;
			}
		}

		return $require;
	}


	/**
	 * The constraints of `composer.json` the targets widen, `^3.2 || ^4.0` for `^3.2`; nothing for a target they allow.
	 * @param  list<Target>  $targets
	 * @return array<string, string>
	 */
	private function widenConstraints(array $targets): array
	{
		$require = [];
		foreach ($targets as $target) {
			$constraint = $this->project->required[$target->package] ?? null;
			if ($constraint !== null && !Versions::isSubset($target->version, $constraint)) {
				$require[$target->package] = "$constraint || ^$target->version";
			}
		}

		return $require;
	}


	/**
	 * The versions of the packages of upgrading data the project and DressCode have, by which a plan says what it was
	 * made with; the data of PHP travel with DressCode.
	 * @return array<string, string>
	 */
	public static function findDataVersions(ProjectPackages $project): array
	{
		$versions = [PackageDiscovery::Php => Application::Version];
		foreach ($project->installed as $package => $installed) {
			if (isset($installed['extra']['dresscode']['upgrading-dir'])) {
				$versions[$package] = $installed['version'] ?? 'dev';
			}
		}

		foreach (array_keys(PackageDiscovery::findInstalledDataPackages()) as $package) {
			$versions[$package] ??= InstalledVersions::getPrettyVersion($package) ?? 'dev';
		}

		ksort($versions);
		return $versions;
	}
}
