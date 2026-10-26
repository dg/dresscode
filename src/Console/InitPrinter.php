<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{PhpVersionSource, Versions};
use DressCode\Interop\{Formatter, FormatterCandidate};
use DressCode\Measuring\{
	LeftOutReason, ProjectFacts, Proposal, ProposedValue, Region, Scope, Shift, ShiftKind, StandardReason, ValueOrigin,
};
use Nette\CommandLine\Console;
use function count, sprintf;


/**
 * Writes what `dresscode init` finds, phase by phase, in the words of its consequences: the project and what is left
 * out of it, the version of PHP, the standard and what the code departs from it in, what a fix would change, and what
 * to run next.
 * @internal
 */
final readonly class InitPrinter
{
	/** the version of the JSON document, a key removed, renamed or meaning another thing being a new one */
	public const Version = 1;

	/** the entries left out the output names one by one, the rest only counted */
	private const ListedLeftOut = 5;

	/** the values taken from the code the output names one by one */
	private const ListedValues = 8;


	public function __construct(
		private Console $console,
	) {
	}


	/**
	 * The project, what of it is left out and why, the formatter it has, and the version of PHP its code is fixed for.
	 * @param  list<FormatterCandidate>  $unchosen  the other configurations of formatters the project has, where nobody chose
	 */
	public function printScope(ProjectFacts $project, Scope $scope, array $unchosen = []): string
	{
		$out = $this->label('Project') . sprintf(
			"%s %s%s\n",
			Markup::formatCount(count($scope->files), 'PHP file'),
			$scope->isFromGit() ? 'in git' : 'in ' . implode(', ', $scope->listPaths()),
			$project->name === null ? '' : ", $project->name",
		);
		$out .= $this->printLeftOut(array_filter($scope->listExcludePaths(), fn(LeftOutReason $why) => $why !== LeftOutReason::Unparsed));
		if ($scope->untracked) {
			$out .= $this->label('') . 'not measured: ' . $this->formatFiles(count($scope->untracked))
				. " git does not track, which the first fix takes too\n";
		}

		if ($config = $project->formatterConfig) {
			$out .= $this->label('Formatter')
				. "{$config->formatter->value}, `$config->file`"
				. ($config->runBy === null ? '' : ", run by `$config->runBy`")
				. "\n"
				. ($config->unread === []
					? ''
					: $this->label('') . 'its scope may differ, as `' . implode('`, `', $config->unread) . "` could not be read\n")
				. ($unchosen === []
					? ''
					: $this->label('') . 'taken as the first, the project having also '
						. implode(' and ', array_map(fn(FormatterCandidate $other) => $other->format(), $unchosen))
						. "; `--formatter` names another\n");
		}

		$lowest = Versions::findLowestVersion($project->phpTarget) ?? $project->phpTarget;
		return $out . $this->label('PHP') . match ($project->phpTargetSource) {
			PhpVersionSource::Default => "$lowest, `composer.json` names none",
			default => "$lowest and newer, from `composer.json`",
		} . (version_compare($lowest, '8.0', '<') ? '; no fix writes what PHP 8 brought' : '') . "\n";
	}


	/** The standard, and what the code departs from it in and what of that the file writes as the code has it. */
	public function printMeasurements(Proposal $proposal): string
	{
		$standard = $proposal->standard;
		$changed = $standard->changedFiles;
		$chosen = implode(', ', $standard->presets);
		$next = array_key_first(array_diff_key($changed, [$chosen => true]));
		$out = $this->label('Standard') . "$chosen (" . $standard->describe() . ')' . match ($standard->reason) {
			StandardReason::Given => ", as given\n",
			StandardReason::Formatter => ', ' . Formatter::Pint->value . " formats the project in this style\n",
			StandardReason::Fewest => sprintf(
				", the nearest to your code by the files a fix changes%s; `--use` names another\n",
				$next === null ? '' : ", $changed[$next] under $next",
			),
		};
		$out .= $this->label('Measured') . 'your code departs from it in ' . $this->formatDecisions(count($standard->residue))
			. ' over ' . $this->formatFiles(count($proposal->sample->files)) . "\n";
		if ($proposal->isSampled()) {
			$directories = $proposal->sample->directories;
			$out .= $this->label('Sample') . count($proposal->sample->files) . ' of ' . count($proposal->scope->files) . ' files'
				. (count($directories) > 1
					? ', every directory its share: ' . implode(', ', array_map(
						fn(string $directory, array $counts) => ($directory === '' ? '.' : $directory) . " $counts[0] of $counts[1]",
						array_keys(array_slice($directories, 0, 5)),
						array_slice($directories, 0, 5),
					)) . (count($directories) > 5 ? ' and more' : '')
					: '')
				. "\n";
		}

		$taken = array_values(array_filter($proposal->values, fn(ProposedValue $proposed) => !$proposed->isKept()));
		foreach (array_slice($taken, 0, self::ListedValues) as $i => $proposed) {
			$out .= $this->label($i === 0 ? 'As written' : '')
				. "$proposed->path: " . $proposal->standard->formatValue($proposed->path, $proposed->value) . "   $proposed->comment\n";
		}

		if (count($taken) > self::ListedValues) {
			$out .= $this->label('') . sprintf("and %d more, each in the file\n", count($taken) - self::ListedValues);
		}

		foreach ($proposal->findRegions() as $i => $region) {
			$out .= $this->label($i === 0 ? 'Differs' : '') . sprintf(
				"%s: %s %s in %s files; an override for `%s` would keep it, none is written\n",
				$region->directory,
				$region->path,
				$proposal->standard->formatValue($region->path, $region->value),
				$region->files === $region->measured ? "all $region->measured" : "$region->files of $region->measured",
				$region->directory,
			);
		}

		$unchecked = count(array_filter(
			$proposal->values,
			fn(ProposedValue $proposed) => $proposed->isKept() && $proposed->origin !== ValueOrigin::OverBudget,
		));
		return $out . ($unchecked
			? $this->label('Unchecked')
				. $this->formatDecisions($unchecked)
				. ($unchecked === 1
					? " left as your code has it (`keep`), with the reason in the file\n"
					: " left as your code has them (`keep`), each with the reason in the file\n")
			: '');
	}


	/**
	 * What a fix of the configuration would change over the whole scope, what it leaves, and what the budget left
	 * unchecked; the files a check found unparsable, which the configuration leaves out.
	 */
	public function printVerification(Proposal $proposal): string
	{
		$verification = $proposal->verification ?? throw new \LogicException('The proposal is not verified.');
		$out = $this->label('Checked') . sprintf(
			"%s: a fix changes %s%s%s\n",
			count($proposal->scope->files) === 1 ? 'the 1 file' : 'all ' . Markup::formatCount(count($proposal->scope->files), 'file'),
			$this->formatFiles($verification->changedFiles),
			$verification->foundationFiles
				? ", $verification->foundationFiles of them for the indentation, the line endings or the long lines the rest of the code has"
				: '',
			$verification->leftFindings
				? sprintf(
					'; %s in %s that no rule fixes go to the baseline',
					Markup::formatCount($verification->leftFindings, 'finding'),
					$this->formatFiles($verification->leftFiles),
				)
				: '',
		);
		$dropped = count(array_filter($proposal->values, fn(ProposedValue $proposed) => $proposed->origin === ValueOrigin::OverBudget));
		if ($dropped) {
			$out .= $this->label('') . ($dropped === 1 ? '1 more decision is' : "$dropped more decisions are")
				. ' left as your code has '
				. ($dropped === 1 ? 'it' : 'them')
				. ", as the fixes together would change more than {$this->formatFiles($verification->budget)}"
				. ", the indentation, the line endings, the long lines and your answers aside (`--budget` raises the limit)\n";
		}

		$failed = $verification->result->failed;
		if ($failed) {
			$out .= $this->label('Failed') . $this->formatFiles(count($failed)) . ' a rule failed in, of which nothing above is counted: '
				. implode(', ', array_slice($failed, 0, self::ListedLeftOut)) . (count($failed) > self::ListedLeftOut ? ' and more' : '') . "\n";
		}

		return $out . $this->printLeftOut(array_filter(
			$proposal->scope->listExcludePaths(),
			fn(LeftOutReason $why) => $why === LeftOutReason::Unparsed,
		));
	}


	/** The commands to run next: a fix where it changes something, then the baseline where something remains. */
	public function printNext(Proposal $proposal, bool $written = true): string
	{
		$verification = $proposal->verification ?? throw new \LogicException('The proposal is not verified.');
		[$changed, $left] = [$verification->changedFiles, $verification->leftFindings];
		$out = ($written
			? $this->label('Written') . "`dresscode.neon`\n"
			: $this->label('Proposed') . "`dresscode.neon` below, nothing written, as `--dry-run` asks\n")
			. $this->label('Next') . match (true) {
				$changed > 0 => "`dresscode fix`        rewrites {$this->formatFiles($changed)} in place (`dresscode check --diff` shows the changes first); until then `check` reports them\n",
				$left > 0
					=> "`dresscode baseline`   records the {$this->formatFindings($left)} that no rule fixes, so that `check` passes\n",
				$verification->result->failed !== [] => "`dresscode check`      reports the files a rule failed in\n",
				default => "`dresscode check`      passes already\n",
			};
		return $out . ($changed > 0 && $left > 0
			? $this->label('Then')
				. "`dresscode baseline`   records the {$this->formatFindings($left)} in {$this->formatFiles($verification->leftFiles)} that no rule fixes, so that `check` passes\n"
			: '')
			. ($proposal->typesVerification === null
				? ''
				: $this->label('Types') . 'checked once more with the types of the code, which the counts above go without: the first fix rewrites '
					. $this->formatFiles($proposal->typesVerification->changedFiles)
					. " and leaves {$this->formatFindings($proposal->typesVerification->leftFindings)} then\n")
			. (($failed = $proposal->findFailedFiles()) === []
				? ''
				: $this->label('Partial') . 'a rule failed in ' . $this->formatFiles(count($failed))
					. ", which the measuring left out, so the decisions rest on the other files, as the head of `dresscode.neon` says\n");
	}


	/** Why nothing was written: a rule failed under every standard, so none of them could be chosen as the nearest. */
	public function printUnsure(Proposal $proposal): string
	{
		$failed = $proposal->standard->failed;
		return $this->label('Failed') . 'a rule failed under every standard in ' . implode(
			', ',
			array_slice($failed, 0, self::ListedLeftOut),
		)
			. (count($failed) > self::ListedLeftOut ? ' and more' : '')
			. ", so none of them can be chosen as the nearest and nothing was written; `--use <standard>` measures against the one you name\n";
	}


	/**
	 * What has moved since the configuration that exists was written, and how to see what a value changes.
	 * @param  list<Shift>  $shifts
	 */
	public function printShifts(Proposal $proposal, array $shifts, string $file): string
	{
		$overridden = array_keys(array_filter($proposal->scope->leftOut, fn(LeftOutReason $why) => $why === LeftOutReason::Overridden));
		$compared = $this->label('Compared') . "the code with the standard and the decisions of `$file`, over the files it checks"
			. ($overridden === []
				? ''
				: ', but ' . $this->formatFiles(count($overridden)) . ' an override of it gives decisions of their own')
			. "\n";
		$out = '';
		foreach ($shifts as $shift) {
			$out .= $this->label($out === '' ? 'Moved' : '')
				. "$shift->path: `$file` has "
				. ($shift->current ?? 'nothing')
				. match ($shift->kind) {
					ShiftKind::Measured => ", init now writes $shift->measured   ($shift->comment)\n",
					ShiftKind::HeldToStandard => ", the code holds to $shift->measured of the standard\n",
				};
		}

		return $compared . ($out ?: $this->label('Moved') . "nothing: `$file` says what init measures\n")
			. "\n`$file` exists, so nothing is written; `dresscode check --set path=value` shows what a value would change.\n";
	}


	/** Says that an override of the configuration that exists gives every file it checks decisions of their own. */
	public function printNothingCompared(string $file): string
	{
		return $this->label('Compared') . "nothing, as an override of `$file` gives every file it checks decisions of their own, which init does not measure\n"
			. "\n`$file` exists, so nothing is written.\n";
	}


	/**
	 * What else the project may do: run DressCode where it runs its formatter, translate the rules of the formatter, which
	 * init does not read, and rewrite the comments that silence it.
	 */
	public function printAlso(ProjectFacts $project, int $suppressions): string
	{
		$config = $project->formatterConfig;
		if ($config === null) {
			return '';
		}

		$out = $this->label('Also') . "`dresscode check` can take the place of {$config->formatter->value}"
			. ($config->runBy === null ? ' where you run it' : " in `$config->runBy`")
			. "\n" . $this->label('') . "its rules were not read; `dresscode migrate config $config->file` translates them instead of measuring the code\n";
		return $out . ($suppressions ? $this->label('') . Markup::formatSuppressionsHint($suppressions) . "\n" : '');
	}


	/**
	 * The proposal as the data the format `json` writes: the scope, the standard, what the file writes and why, and what
	 * the check over the whole scope says.
	 * @param  ?string  $configuration  the configuration init proposes; null over one that exists, or none chosen
	 * @param  ?string  $written  the file the configuration was written to
	 * @param  ?list<Shift>  $shifts  what has moved since the configuration that exists, the code being compared with it
	 * @return array<string, mixed>
	 */
	public function toArray(
		Proposal $proposal,
		?string $configuration = null,
		?string $written = null,
		?array $shifts = null,
	): array
	{
		$standard = $proposal->standard;
		$verification = $proposal->verification;
		return [
			...self::toScopeArray($proposal->project->root, $proposal->scope),
			'sample' => ['files' => count($proposal->sample->files), 'directories' => (object) $proposal->sample->directories],
			'standard' => [
				'use' => $standard->presets,
				'reason' => lcfirst($standard->reason->name),
				'changedFiles' => (object) $standard->changedFiles,
				'unsure' => $standard->unsure,
			],
			'values' => array_map(fn(ProposedValue $proposed) => [
				'path' => $proposed->path,
				'value' => $proposed->value,
				'comment' => $proposed->comment,
				'origin' => lcfirst($proposed->origin->name),
			], array_values($proposal->values)),
			'regions' => array_map(fn(Region $region) => (array) $region, $proposal->findRegions()),
			'verification' => $verification === null ? null : [
				'changedFiles' => $verification->changedFiles,
				'foundationFiles' => $verification->foundationFiles,
				'leftFindings' => $verification->leftFindings,
				'leftFiles' => $verification->leftFiles,
			],
			'failed' => $proposal->findFailedFiles(),
			'configuration' => $configuration,
			'written' => $written,
			...$shifts === null
				? []
				: ['shifts' => array_map(fn(Shift $shift) => [...(array) $shift, 'kind' => lcfirst($shift->kind->name)], $shifts)],
		];
	}


	/**
	 * The root and the scope as the data the format `json` writes, all it writes where nothing was measured.
	 * @return array<string, mixed>
	 */
	public static function toScopeArray(string $root, Scope $scope): array
	{
		return [
			'version' => self::Version,
			'root' => $root,
			'scope' => [
				'files' => count($scope->files),
				'paths' => $scope->listPaths(),
				'excluded' => (object) array_map(fn(LeftOutReason $why) => $why->value, $scope->listExcludePaths()),
				'untracked' => $scope->untracked,
			],
		];
	}


	/** @param  array<string, LeftOutReason>  $entries  path => why */
	private function printLeftOut(array $entries): string
	{
		$out = '';
		$width = $entries ? max(array_map(strlen(...), array_slice(array_keys($entries), 0, self::ListedLeftOut))) : 0;
		foreach (array_slice($entries, 0, self::ListedLeftOut, preserve_keys: true) as $path => $why) {
			$out .= $this->label($out === '' ? 'Excluded' : '') . str_pad($path, $width) . "   $why->value\n";
		}

		$more = count($entries) - self::ListedLeftOut;
		return $out . ($more > 0 ? $this->label('') . "and $more more, each in `excludePaths`\n" : '');
	}


	private function formatDecisions(int $count): string
	{
		return match ($count) {
			0 => 'no decision',
			1 => '1 decision',
			default => "$count decisions",
		};
	}


	private function formatFiles(int $count): string
	{
		return match ($count) {
			0 => 'no file',
			1 => '1 file',
			default => "$count files",
		};
	}


	private function formatFindings(int $count): string
	{
		return Markup::formatCount($count, 'finding');
	}


	private function label(string $label): string
	{
		return Markup::formatLabel($this->console, $label);
	}
}
