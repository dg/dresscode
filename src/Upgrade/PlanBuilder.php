<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\Config\ProjectPackages;


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
	) {
	}


	public function build(Estimate $estimate, ?string $testCommand = null, ?string $quickTestCommand = null): Plan
	{
		$library = $estimate->library;
		$constraints = new Constraints($this->project);
		$steps = [];
		foreach ($estimate->blockers as $blockers) {
			if ($blockers->packages !== []) {
				$steps[] = new ManualStep(
					'make way for '
					. $blockers->target->format()
					. ': '
					. implode(', ', array_map(fn(Blocker $blocker) => $blocker->package, $blockers->packages)),
					$blockers->target,
				);
			} elseif ($blockers->unknown !== null) { // not knowing what refuses the target is no knowing that nothing does
				$steps[] = new ManualStep("make way for {$blockers->target->format()}, which Composer could not tell", $blockers->target);
			}
		}

		$steps[] = self::createFix('works on the versions allowed today', $estimate->findTopics(Phase::Bridge, 'deprecations'));
		$require = $library ? $constraints->widen($estimate->targets) : $constraints->raise($estimate->targets);
		// a platform below the target is raised even where composer.json requires the target already
		$platform = $library ? null : $constraints->raisePlatform($estimate->targets);
		if ($require !== [] || $platform !== null) {
			$versions = Target::collectVersions($estimate->targets);
			$changes = [];
			foreach ($require as $package => $constraint) {
				$bound = $library ? null : Constraints::findPassedUpperBound($this->project->required[$package] ?? '', $versions[$package]);
				$changes[] = "$package to $constraint" . ($bound === null ? '' : " past its upper bound $bound");
			}

			if ($platform !== null) {
				$changes[] = "config.platform.php to $platform";
			}

			$steps[] = new ComposerStep(
				($library ? 'widen ' : 'raise ') . implode(', ', $changes),
				$require,
				update: !$library,
				platform: $platform,
			);
		}

		if (!$library) {
			$steps[] = self::createFix('needs the targets', $estimate->findTopics(Phase::Jump, 'deprecations'));
			$steps[] = self::createFix('code for the versions given up', [
				...$estimate->findTopics(Phase::Bridge, 'cleanup'),
				...$estimate->findTopics(Phase::Jump, 'cleanup'),
			]);
			// the raise installs the lowest versions, which the code is written for, and the application runs the newest
			if ($require !== []) {
				$steps[] = new UpdateStep('update to the newest versions');
			}
		}

		$steps[] = self::createFix('what the targets offer', [
			...$estimate->findTopics(Phase::Bridge, 'modernizations'),
			...$library ? [] : $estimate->findTopics(Phase::Jump, 'modernizations'),
		], skip: true);

		$provenance = Provenance::current($this->project);
		return new Plan(
			$estimate->targets,
			array_values(array_filter($steps)),
			$provenance->tool,
			$provenance->data,
			$testCommand,
			$quickTestCommand,
			$estimate->paths,
			array_keys($estimate->unprocessed),
			$estimate->missingData,
		);
	}


	/** @param  list<Topic>  $topics */
	private static function createFix(string $title, array $topics, bool $skip = false): ?FixStep
	{
		$names = array_values(array_unique(array_map(fn(Topic $topic) => $topic->name, $topics)));
		return $names === [] ? null : new FixStep($title, $names, $skip);
	}
}
