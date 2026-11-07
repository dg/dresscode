<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{PhpVersionSource, Versions};
use DressCode\Engine\Helpers;
use DressCode\Interop\{Formatter, FormatterCandidate, FormatterConfig};
use DressCode\Measuring\{
	LeftOutReason, ProjectFacts, Proposal, ProposedValue, Question, Region, Scope, Shift, ShiftKind, StandardReason, ValueOrigin,
};
use DressCode\Violation;
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
		$out = $this->formatLine('Project', sprintf(
			'%s %s%s',
			Helpers::formatCount(count($scope->files), 'PHP file'),
			$scope->isFromGit() ? 'in git' : 'in ' . implode(', ', $scope->listPaths()),
			$project->name === null ? '' : ", $project->name",
		));
		$out .= $this->printLeftOut(array_filter($scope->listExcludePaths(), fn(LeftOutReason $why) => $why !== LeftOutReason::Unparsed));
		if ($scope->untracked) {
			$out .= $this->formatLine(
				'',
				'not measured: '
				. Helpers::formatCount(count($scope->untracked), 'file')
				. ' git does not track, which the first fix takes too',
			);
		}

		if ($config = $project->formatterConfig) {
			$out .= $this->formatLine(
				'Formatter',
				"{$config->formatter->value}, `$config->file`" . ($config->runBy === null ? '' : ", run by `$config->runBy`"),
			)
				. ($config->unread === []
					? ''
					: $this->formatLine('', 'its scope may differ, as `' . implode('`, `', $config->unread) . '` could not be read'))
				. ($unchosen === []
					? ''
					: $this->formatLine(
						'',
						'taken as the first, the project having also '
						. implode(' and ', array_map(fn(FormatterCandidate $other) => $other->format(), $unchosen))
						. '; a terminal asks which, without `--yes`',
					));
		}

		$lowest = Versions::findLowestVersion($project->phpTarget) ?? $project->phpTarget;
		$older = version_compare($lowest, '8.0', '<') ? '; no fix writes what PHP 8 brought' : '';
		return $out . match ($project->phpTargetSource) {
			PhpVersionSource::Default => $this->formatLine('PHP', $lowest, ', `composer.json` names none', $older),
			default => $this->formatLine('PHP', "$lowest and newer", ', from `composer.json`', $older),
		};
	}


	/** The standard, and what the code departs from it in and what of that the file writes as the code has it. */
	public function printMeasurements(Proposal $proposal): string
	{
		$standard = $proposal->standard;
		$changed = $standard->changedFiles;
		$chosen = implode(', ', $standard->presets);
		$named = implode(', ', array_map(fn(string $preset) => "`$preset`", $standard->presets));
		$next = array_key_first(array_diff_key($changed, [$chosen => true]));
		$out = $this->formatLine('Standard', $named, ' (' . $standard->describe() . ')' . match ($standard->reason) {
			StandardReason::Given => ', as given',
			StandardReason::Formatter => ', ' . Formatter::Pint->value . ' formats the project in this style',
			StandardReason::Successor => sprintf(
				', the successor of `psr12`, under which a fix changes fewer files, %d; `--use` names another',
				$changed['psr12'],
			),
			StandardReason::Fewest => sprintf(
				', the nearest to your code%s; `--use` names another',
				$next === null || $changed[$next] === ($changed[$chosen] ?? 0)
					? ''
					: ', as a fix changes '
						. Helpers::formatCount($changed[$chosen] ?? 0, 'file')
						. " under it and $changed[$next] under `$next`",
			),
		});
		$out .= $this->formatLine(
			'Measured',
			'your code departs from it in ' . Helpers::formatCount(count($standard->residue), 'decision')
			. ' over ' . Helpers::formatCount(count($proposal->sample->files), 'file'),
			$changed[$chosen] ?? 0 ? "; $named alone would rewrite {$changed[$chosen]} of them" : '',
		);
		if ($proposal->isSampled()) {
			$directories = $proposal->sample->directories;
			$out .= $this->formatLine(
				'Sample',
				count($proposal->sample->files) . ' of ' . count($proposal->scope->files) . ' files',
				count($directories) > 1
					? ', every directory its share: ' . implode(', ', array_map(
						fn(string $directory, array $counts) => ($directory === '' ? '.' : $directory) . " $counts[0] of $counts[1]",
						array_keys(array_slice($directories, 0, 5)),
						array_slice($directories, 0, 5),
					)) . (count($directories) > 5 ? ' and more' : '')
					: '',
			);
		}

		$written = $proposal->findWrittenValues();
		$taken = array_values(array_filter($written, fn(ProposedValue $proposed) => !$proposed->isKept()));
		$listed = array_slice($taken, 0, self::ListedValues);
		$codes = array_map(
			fn(ProposedValue $proposed) => Violation::formatCode("$proposed->path: " . $standard->formatValue($proposed->path, $proposed->value)),
			$listed,
		);
		$width = $codes ? max(array_map(strlen(...), $codes)) : 0;
		foreach ($listed as $i => $proposed) {
			$out .= $this->formatLine($i === 0 ? 'Your style' : '', str_pad($codes[$i], $width), '   ' . $proposed->comment);
		}

		if (count($taken) > self::ListedValues) {
			$out .= $this->formatLine('', sprintf('and %d more', count($taken) - self::ListedValues), ', each in the file');
		}

		foreach ($proposal->findRegions() as $i => $region) {
			$out .= $this->formatLine($i === 0 ? 'Differs' : '', sprintf(
				'%s: %s in %s files',
				$region->directory,
				Violation::formatCode("$region->path: " . $standard->formatValue($region->path, $region->value)),
				$region->files === $region->measured ? "all $region->measured" : "$region->files of $region->measured",
			), "; an override for `$region->directory` would keep it, none is written");
		}

		$unchecked = count(array_filter(
			$written,
			fn(ProposedValue $proposed) => $proposed->isKept() && $proposed->origin !== ValueOrigin::OverBudget,
		));
		return $out . (match ($unchecked) {
			0 => '',
			1 => $this->formatLine('Left as is', '1 decision left as your code has it (`keep`)', ', with the reason in the file'),
			default => $this->formatLine(
				'Left as is',
				"$unchecked decisions left as your code has them (`keep`)",
				', each with the reason in the file',
			),
		});
	}


	/**
	 * What a fix of the configuration would change over the whole scope, what it leaves, and what the budget left
	 * unchecked; the files a check found unparsable, which the configuration leaves out.
	 */
	public function printVerification(Proposal $proposal): string
	{
		$verification = $proposal->verification ?? throw new \LogicException('The proposal is not verified.');
		$out = '';
		$dropped = array_values(array_filter(
			$proposal->values,
			fn(ProposedValue $proposed) => $proposed->origin === ValueOrigin::OverBudget,
		));
		if ($dropped) {
			$pronoun = count($dropped) === 1 ? 'it' : 'them';
			$listed = array_map(fn(ProposedValue $proposed) => "`$proposed->path`", array_slice($dropped, 0, self::ListedLeftOut));
			$out = $this->formatLine(
				'Held back',
				Helpers::formatCount(count($dropped), 'more decision stays', 'more decisions stay') . " as your code has $pronoun",
				", as fixing $pronoun too would change more than "
				. Helpers::formatCount($verification->budget, 'file')
				. ' (`--budget` raises the limit)',
				': ' . implode(', ', $listed) . (count($dropped) > self::ListedLeftOut ? ' and more' : ''),
			);
		}

		$out .= $this->formatLine(
			'Checked',
			($verification->checkedFiles === 1 ? 'the 1 file' : 'all ' . Helpers::formatCount($verification->checkedFiles, 'file'))
			. ': with these decisions a fix changes ' . Helpers::formatCount($verification->changedFiles, 'file'),
			$verification->foundationFiles
				? ", $verification->foundationFiles of them for the indentation, the line endings or the long lines"
				: '',
			$verification->leftFindings
				? sprintf(
					'; %s in %s that no rule fixes go to the baseline',
					Helpers::formatCount($verification->leftFindings, 'finding'),
					Helpers::formatCount($verification->leftFiles, 'file'),
				)
				: '',
		);
		$failed = $verification->result->failed;
		if ($failed) {
			$out .= $this->formatLine(
				'Failed',
				Helpers::formatCount(count($failed), 'file') . ' a rule failed in, of which nothing above is counted: '
				. implode(', ', array_slice($failed, 0, self::ListedLeftOut)) . (count($failed) > self::ListedLeftOut ? ' and more' : ''),
			);
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
		$out = ($written ? '' : $this->formatLine('Proposed', '`dresscode.neon` below, nothing written, as `--dry-run` asks'))
			. match (true) {
				$changed > 0 => $this->formatLine(
					'Next',
					'`dresscode fix`        rewrites ' . Helpers::formatCount($changed, 'file') . ' in place',
					' (`dresscode check --diff` shows the changes first); until then `check` reports them',
				),
				$left > 0 => $this->formatLine(
					'Next',
					'`dresscode baseline`   records the ' . Helpers::formatCount($left, 'finding') . ' that no rule fixes',
					', so that `check` passes',
				),
				$verification->result->failed !== []
					=> $this->formatLine('Next', '`dresscode check`      reports the files a rule failed in'),
				default => $this->formatLine('Next', '`dresscode check`      passes already'),
			};
		return $out . ($changed > 0 && $left > 0
			? $this->formatLine(
				'Then',
				'`dresscode baseline`   records the ' . Helpers::formatCount($left, 'finding') . ' in '
				. Helpers::formatCount($verification->leftFiles, 'file') . ' that no rule fixes',
				', so that `check` passes',
			)
			: '')
			. ($proposal->typesVerification === null
				? ''
				: $this->formatLine(
					'Types',
					'checked once more with the types of the code, which the counts above go without: the first fix rewrites '
					. Helpers::formatCount($proposal->typesVerification->changedFiles, 'file')
					. ' and leaves ' . Helpers::formatCount($proposal->typesVerification->leftFindings, 'finding') . ' then',
				))
			. (($failed = $proposal->findFailedFiles()) === []
				? ''
				: $this->formatLine(
					'Partial',
					'a rule failed in ' . Helpers::formatCount(count($failed), 'file')
					. ', which the measuring left out, so the decisions rest on the other files, as the head of `dresscode.neon` says',
				));
	}


	/** The last line of a run that wrote the file; red where a rule failed and the measuring left files out. */
	public function printWritten(Proposal $proposal, string $path): string
	{
		return "\n" . $this->console->color(
			$proposal->isPartial() ? 'white/red' : 'white/green',
			'WRITTEN  ' . Markup::formatFileLink($this->console, 'dresscode.neon', $path),
		) . "\n";
	}


	/** Why nothing was written: a rule failed under every standard, so none of them could be chosen as the nearest. */
	public function printUnsure(Proposal $proposal): string
	{
		$failed = $proposal->standard->failed;
		return $this->formatLine(
			'Failed',
			'a rule failed under every standard in ' . implode(', ', array_slice($failed, 0, self::ListedLeftOut))
			. (count($failed) > self::ListedLeftOut ? ' and more' : '')
			. ', so none of them can be chosen as the nearest and nothing was written; `--use <standard>` measures against the one you name',
		);
	}


	/**
	 * A question asked in a terminal, numbered in its round where the round has more: what it asks, the decisions or
	 * a place of the code it is about with what a fix makes of it, and its answers by number. The code of the place is
	 * drawn as it is, its backticks among it.
	 * @param  list<string>  $preview  the lines of the place as a fix rewrites them, each a line of a diff
	 */
	public function printQuestion(Question $question, int $number = 1, int $count = 1, array $preview = []): string
	{
		$highlight = fn(string $text) => Markup::highlightCode($this->console, $text);
		$example = $question->example;
		$out = Markup::wrapLines(
			$this->console,
			($count > 1 ? $this->console->color('gray', "($number/$count)") . ' ' : '')
			. $highlight($question->text)
			. ($question->reason === null ? '' : ' ' . $this->formatAside($question->reason)),
		) . "\n";
		$width = $question->decisions ? max(array_map(strlen(...), array_keys($question->decisions))) : 0;
		foreach ($question->decisions as $code => $cost) {
			$out .= $this->formatLine('', str_pad($code, $width), "   $cost");
		}

		if ($example !== null) {
			$out .= Markup::wrapLines(
				$this->console,
				$this->label('') . $this->console->color('gray', 'e.g.') . ' '
				. $this->console->color('white', "$example->file:$example->line") . '  ' . $highlight($example->message),
			) . "\n";
			foreach (self::formatPreview($preview) as $line) {
				$color = match ($line[0]) {
					'-' => 'red',
					'+' => 'green',
					default => null,
				};
				$out .= $this->label('') . '     ' . $this->console->color($color, $line) . "\n";
			}
		}

		foreach ($question->answers as $i => $answer) {
			$out .= '  [' . ($i + 1) . '] ' . $highlight($answer->label) . ($i === 0 ? $this->formatAside(' (recommended)') : '') . "\n";
		}

		return $out;
	}


	/**
	 * The lines of a diff with the indentation all of them share left out and a space after the sign.
	 * @param  list<string>  $lines
	 * @return list<string>
	 */
	private static function formatPreview(array $lines): array
	{
		$codes = Markup::stripIndentation(array_map(fn(string $line) => substr($line, 1), $lines));
		return array_map(fn(string $line, string $code) => $line[0] . ' ' . $code, $lines, $codes);
	}


	/** The questions the answers given have left to the user, which no round before could ask. */
	public function printNextRound(int $count): string
	{
		return 'Your answers leave ' . Helpers::formatCount($count, 'more question') . ":\n";
	}


	/**
	 * What is the user's to decide where nobody was asked: each question as the line or the command that answers it
	 * otherwise than the recommended answer init took.
	 * @param  list<Question>  $questions
	 */
	public function printInstructions(array $questions): string
	{
		$out = '';
		foreach ($questions as $question) {
			$out .= $this->formatLine($out === '' ? 'Your call' : '', $question->instruction);
		}

		return $out;
	}


	/** The configuration of the formatter that could not be read, so its rules are not compared with the code. */
	public function printUnreadable(FormatterConfig $config, string $error): string
	{
		return $this->formatLine('Formatter', "`$config->file` could not be read, so its rules are not compared with your code: $error");
	}


	/**
	 * What has moved since the configuration that exists was written, and how to see what a value changes.
	 * @param  list<Shift>  $shifts
	 */
	public function printShifts(Proposal $proposal, array $shifts, string $file): string
	{
		$overridden = array_keys(array_filter($proposal->scope->leftOut, fn(LeftOutReason $why) => $why === LeftOutReason::Overridden));
		$compared = $this->formatLine(
			'Compared',
			"the code with the standard and the decisions of `$file`, over the files it checks"
			. ($overridden === []
				? ''
				: ', but ' . Helpers::formatCount(count($overridden), 'file') . ' an override of it gives decisions of their own'),
		);
		$out = '';
		foreach ($shifts as $shift) {
			$has = "`$shift->path`: `$file` has " . ($shift->current === null ? 'nothing' : Violation::formatCode($shift->current));
			$out .= match ($shift->kind) {
				ShiftKind::Measured => $this->formatLine(
					$out === '' ? 'Moved' : '',
					$has . ($shift->cost
						? ', a fix of it rewrites ' . ($proposal->isSampled() ? 'about ' : '') . Helpers::formatCount($shift->cost, 'file')
						: '')
					. ', init now writes ' . Violation::formatCode($shift->measured),
					"   ($shift->comment)",
				),
				ShiftKind::HeldToStandard => $this->formatLine(
					$out === '' ? 'Moved' : '',
					$has . ', the code holds to ' . Violation::formatCode($shift->measured) . ' of the standard',
				),
			};
		}

		return $compared . ($out ?: $this->formatLine('Moved', "nothing: `$file` says what init measures"))
			. "\n" . Markup::highlightCode(
				$this->console,
				"`$file` exists, so nothing is written; `dresscode check --set path=value` shows what a value would change.",
			) . "\n";
	}


	/** Says that an override of the configuration that exists gives every file it checks decisions of their own. */
	public function printNothingCompared(string $file): string
	{
		return $this->formatLine(
			'Compared',
			"nothing, as an override of `$file` gives every file it checks decisions of their own, which init does not measure",
		) . "\n" . Markup::highlightCode($this->console, "`$file` exists, so nothing is written.") . "\n";
	}


	/**
	 * What else the project may do: run DressCode where it runs its formatter, translate the rules of the formatter, which
	 * the file only compared with the code or did not read, and rewrite the comments that silence it.
	 */
	public function printAlso(ProjectFacts $project, int $suppressions, bool $rulesRead): string
	{
		$config = $project->formatterConfig;
		if ($config === null) {
			return '';
		}

		$out = $this->formatLine(
			'Also',
			"`dresscode check` can take the place of {$config->formatter->value}"
			. ($config->runBy === null ? ' where you run it' : " in `$config->runBy`"),
		) . $this->formatLine('', match (true) {
			$rulesRead => 'its rules were compared with your code, not carried over',
			$config->formatter->runsCode() => "its rules were not read, as `$config->file` is PHP",
			default => 'its rules could not be read',
		} . "; `dresscode migrate config $config->file` translates them instead of measuring the code");
		return $out . ($suppressions ? $this->formatLine('', Markup::formatSuppressionsHint($suppressions)) : '');
	}


	/**
	 * The proposal as the data the format `json` writes: the scope, the standard, what the file writes and why, what the
	 * check over the whole scope says and the lines of `dresscode.neon` answering what init would ask.
	 * @param  list<Question>  $asked  the questions the recommended answers were taken for
	 * @param  ?string  $configuration  the configuration init proposes; null over one that exists, or none chosen
	 * @param  ?string  $written  the file the configuration was written to
	 * @param  ?list<Shift>  $shifts  what has moved since the configuration that exists, the code being compared with it
	 * @return array<string, mixed>
	 */
	public function toArray(
		Proposal $proposal,
		array $asked = [],
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
				'alternatives' => $standard->alternatives,
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
			'instructions' => array_map(fn(Question $question) => $question->instruction, $asked),
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
			$out .= $this->formatLine($out === '' ? 'Excluded' : '', str_pad($path, $width), "   $why->value");
		}

		$more = count($entries) - self::ListedLeftOut;
		return $out . ($more > 0 ? $this->formatLine('', "and $more more", ', each in `excludePaths`') : '');
	}


	/** A line of the report with its newline. */
	private function formatLine(string $label, string $text, string $aside = '', string $rest = ''): string
	{
		return Markup::formatLine($this->console, $label, $text, $aside, $rest) . "\n";
	}


	private function label(string $label): string
	{
		return Markup::formatLabel($this->console, $label);
	}


	private function formatAside(string $text): string
	{
		return Markup::formatAside($this->console, $text);
	}
}
