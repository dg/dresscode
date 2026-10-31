<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Config\{PhpVersionSource, ResolvedConfig, Versions};
use DressCode\Interop\{Formatter, FormatterConfig};
use DressCode\Measuring\{LeftOutReason, Project, Proposal, ProposedValue, Question, Scope, StandardReason, ValueOrigin};
use Nette\CommandLine\Console;
use function count, is_array, sprintf;


/**
 * Writes what `dresscode init` finds, phase by phase, in the words of its consequences: the project and what is left
 * out of it, the version of PHP, the standard and what the code departs from it in, what a fix would change, and what
 * to run next.
 * @internal
 */
final readonly class InitPrinter
{
	/** the entries left out the output names one by one, the rest only counted */
	private const ListedLeftOut = 5;

	/** the values taken from the code the output names one by one */
	private const ListedValues = 8;


	public function __construct(
		private Console $console,
	) {
	}


	/** The project, what of it is left out and why, the formatter it has, and the version of PHP its code is fixed for. */
	public function printScope(Project $project, Scope $scope): string
	{
		$out = $this->label('Project') . sprintf(
			"%s %s%s\n",
			Markup::formatCount(count($scope->files), 'PHP file'),
			$scope->isTracked() ? 'in git' : 'in ' . implode(', ', $scope->listPaths()),
			$project->name === null ? '' : ", $project->name",
		);
		$out .= $this->printLeftOut(array_filter($scope->listExcludePaths(), fn(string $why) => $why !== LeftOutReason::Unparsed->value));
		if ($config = $project->formatterConfig) {
			$out .= $this->label('Formatter')
				. "{$config->formatter->value}, `$config->file`"
				. ($config->runBy === null ? '' : ", run by `$config->runBy`")
				. "\n";
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
		$residues = $standard->residues;
		$chosen = implode(', ', $standard->presets);
		$next = array_key_first(array_diff_key($residues, [$chosen => true]));
		$out = $this->label('Standard') . "$chosen (" . $standard->describe() . ')' . match ($standard->reason) {
			StandardReason::Given => ", as given\n",
			StandardReason::Formatter => ', ' . Formatter::Pint->value . " formats the project in this style\n",
			StandardReason::Successor => sprintf(
				", the successor of psr12; your code departs from it in %s and from psr12 in %d; `--use` names another\n",
				$this->formatDecisions($residues[$chosen]),
				$residues['psr12'],
			),
			StandardReason::Fewest => sprintf(
				", the nearest to your code, which departs from it in %s and from %s in %d; `--use` names another\n",
				$this->formatDecisions($residues[$chosen]),
				$next,
				$residues[(string) $next] ?? 0,
			),
		};
		$out .= $this->label('Measured') . 'your code departs from it in ' . $this->formatDecisions(count($standard->residue))
			. ' over ' . $this->formatFiles(count($proposal->sample->files))
			. ($standard->changedFiles
				? "; $chosen as it is, with no decision below `use`, would rewrite $standard->changedFiles of them"
				: '')
			. "\n";
		$taken = array_values(array_filter($proposal->values, fn(ProposedValue $proposed) => !$proposed->isKept()));
		foreach (array_slice($taken, 0, self::ListedValues) as $i => $proposed) {
			$out .= $this->label($i === 0 ? 'As written' : '')
				. "$proposed->path: " . $this->formatValue($proposed->value) . "   $proposed->comment\n";
		}

		if (count($taken) > self::ListedValues) {
			$out .= $this->label('') . sprintf("and %d more, each in the file\n", count($taken) - self::ListedValues);
		}

		$unchecked = count(array_filter(
			$proposal->values,
			fn(ProposedValue $proposed) => $proposed->isKept() && $proposed->origin !== ValueOrigin::OverBudget,
		));
		return $out . ($unchecked
			? $this->label('Unchecked')
				. $this->formatDecisions($unchecked)
				. " left as your code has them (`keep`), each with the reason in the file\n"
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
			$verification->alignedFiles ? ", $verification->alignedFiles of them to indent them as the rest of the code is" : '',
			$verification->leftPlaces
				? sprintf(
					'; %d places in %s that no rule fixes go to the baseline',
					$verification->leftPlaces,
					$this->formatFiles($verification->leftFiles),
				)
				: '',
		);
		$dropped = count(array_filter($proposal->values, fn(ProposedValue $proposed) => $proposed->origin === ValueOrigin::OverBudget));
		if ($dropped) {
			$out .= $this->label('') . ($dropped === 1
				? '1 more decision is left as your code has it, as its fix would change'
				: "$dropped more decisions are left as your code has them, as a fix of each would change")
				. " more than {$this->formatFiles($verification->budget)} (`--budget` raises the limit)\n";
		}

		return $out . $this->printLeftOut(array_filter(
			$proposal->scope->listExcludePaths(),
			fn(string $why) => $why === LeftOutReason::Unparsed->value,
		));
	}


	/** The commands to run next: a fix where it changes something, then the baseline where something remains. */
	public function printNext(Proposal $proposal): string
	{
		$verification = $proposal->verification ?? throw new \LogicException('The proposal is not verified.');
		[$changed, $left] = [$verification->changedFiles, $verification->leftPlaces];
		$out = $this->label('Written') . "`dresscode.neon`\n" . $this->label('Next') . match (true) {
			$changed > 0 => "`dresscode fix`        rewrites {$this->formatFiles($changed)} in place (`--diff` shows the changes first); until then `check` reports them\n",
			$left > 0 => "`dresscode baseline`   records the $left places that no rule fixes, so that `check` passes\n",
			default => "`dresscode check`      passes already\n",
		};
		return $out . ($changed > 0 && $left > 0
			? $this->label('Then')
				. "`dresscode baseline`   records the $left places in {$this->formatFiles($verification->leftFiles)} that no rule fixes, so that `check` passes\n"
			: '');
	}


	/**
	 * A question asked in a terminal, numbered in its round where the round has more: what it asks, a place of the code
	 * it is about, and its answers by number.
	 */
	public function printQuestion(Question $question, int $number = 1, int $count = 1): string
	{
		$out = ($count > 1 ? "($number/$count) " : '')
			. $question->text
			. "\n"
			. ($question->example === null ? '' : $this->label('') . 'e.g. ' . $question->example->describe() . "\n");
		foreach ($question->answers as $i => $answer) {
			$out .= '  [' . ($i + 1) . "] $answer->label" . ($i === 0 ? ' (recommended)' : '') . "\n";
		}

		return $out;
	}


	/** The questions the answers given have left to the user, which no round before could ask. */
	public function printNextRound(int $count): string
	{
		return 'Your answers leave ' . ($count === 1 ? 'one more question' : "$count more questions") . ":\n";
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
			$out .= $this->label($out === '' ? 'Your call' : '') . $question->instruction . "\n";
		}

		return $out;
	}


	/** The configuration of the formatter that could not be read, so its rules are not compared with the code. */
	public function printUnreadable(FormatterConfig $config, string $error): string
	{
		return $this->label('Formatter') . "`$config->file` could not be read, so its rules are not compared with your code: $error\n";
	}


	/**
	 * What has moved since the configuration that exists was written: every decision init would write otherwise, those
	 * the file sets apart from the standard while the code holds to the standard among them, and how to see what its
	 * value changes.
	 */
	public function printShifts(Proposal $proposal, ResolvedConfig $resolved, string $file): string
	{
		$out = '';
		foreach ($proposal->values as $path => $proposed) {
			$value = ($resolved->decisions[$path] ?? null)?->value;
			$measured = $proposal->standard->formatValue($path, $proposed->value);
			if ($value?->toWrittenText() !== $measured) {
				$cost = $value === null ? null : $proposal->estimateFiles($path, $value->toWrittenData());
				$out .= $this->label($out === '' ? 'Moved' : '')
					. "$path: `$file` has " . ($value?->toWrittenText() ?? 'nothing')
					. ($cost ? ', a fix of it rewrites ' . ($proposal->isSampled() ? 'about ' : '') . $this->formatFiles($cost) : '')
					. ", init now writes $measured   ($proposed->comment)\n";
			}
		}

		// what the file sets apart from the standard while the code holds to the standard, which no residue shows
		foreach ($resolved->decisions as $path => $decision) {
			$standard = $proposal->standard->decisions[$path] ?? null;
			if (
				$standard !== null
				&& $standard->inactive === null
				&& $decision->inactive === null
				&& !isset($proposal->values[$path])
				&& !in_array($path, $proposal->standard->residue, true)
				&& $decision->value->toData() !== $standard->value->toData()
			) {
				$out .= $this->label($out === '' ? 'Moved' : '')
					. "$path: `$file` has {$decision->value->toWrittenText()}, the code holds to {$standard->value->toWrittenText()} of the standard\n";
			}
		}

		return ($out ?: $this->label('Moved') . "nothing: `$file` says what init measures\n")
			. "\n`$file` exists, so nothing is written; `dresscode check --set path=value` shows what a value would change.\n";
	}


	/** What else the project may do: run DressCode where it runs its formatter, and rewrite the comments that silence it. */
	public function printAlso(Project $project, string $suppressionsHint): string
	{
		$config = $project->formatterConfig;
		if ($config === null) {
			return '';
		}

		$out = $this->label('Also') . "`dresscode check` can take the place of {$config->formatter->value}"
			. ($config->runBy === null ? ' where you run it' : " in `$config->runBy`")
			. "\n";
		return $out . ($suppressionsHint === '' ? '' : $this->label('') . "$suppressionsHint\n");
	}


	/** @param  array<string, string>  $entries  path => why */
	private function printLeftOut(array $entries): string
	{
		$out = '';
		$width = $entries ? max(array_map(strlen(...), array_slice(array_keys($entries), 0, self::ListedLeftOut))) : 0;
		foreach (array_slice($entries, 0, self::ListedLeftOut, preserve_keys: true) as $path => $why) {
			$out .= $this->label($out === '' ? 'Left out' : '') . str_pad($path, $width) . "   $why\n";
		}

		$more = count($entries) - self::ListedLeftOut;
		return $out . ($more > 0 ? $this->label('') . "and $more more, each in `excludePaths`\n" : '');
	}


	private function formatValue(mixed $value): string
	{
		return is_array($value) ? '[' . implode(', ', $value) . ']' : (string) $value;
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


	private function label(string $label): string
	{
		return Markup::formatLabel($this->console, $label);
	}
}
