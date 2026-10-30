<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Measuring;

use DressCode\ConfigurationException;
use DressCode\Interop\{FormatterConfig, Translation};
use DressCode\Rules\Classes\NameCasingRule;
use function count, sprintf, strlen;


/**
 * What `dresscode init` asks, where the measuring left the choice to the user: the standard first, then the decisions,
 * the costliest first, and then what the answers left to the user in turn. Every question carries an answer that leaves
 * the code as it is, which is recommended.
 * @internal
 */
final class Questions
{
	/** the questions of decisions asked at most, before any answer */
	private const MaxDecisions = 5;

	/** the names no rule fixes that must share a pattern for init to offer it */
	private const MinNames = 3;

	/** @var array<string, true>  the decisions a question was found about, asked or not */
	private array $found = [];


	public function __construct(
		/** the rules of the formatter of the project, which a value of the code may contradict */
		private readonly ?Translation $formatterRules = null,
	) {
	}


	/** A close call of standards nothing of the project decided, asked before the verification, as an answer measures again. */
	public static function findStandardQuestion(Proposal $proposal): ?Question
	{
		$standard = $proposal->standard;
		if (!$standard->alternatives) {
			return null;
		}

		$chosen = implode(', ', $standard->presets);
		$describe = fn(string $name) => sprintf('%s, your code departs from it in %d decisions', $name, $standard->residues[$name] ?? 0);
		$key = 'a';
		$answers = [new Answer($key, $describe($chosen))];
		foreach ($standard->alternatives as $alternative) {
			$key = str_increment($key);
			$answers[] = new Answer($key, $describe($alternative), presets: [$alternative]);
		}

		return new Question(
			"Your code is as near to $chosen as to " . implode(' and ', $standard->alternatives) . '; which standard should it follow?',
			$answers,
			"`dresscode init --use {$standard->alternatives[0]}` measures against {$standard->alternatives[0]} instead, where there is no `dresscode.neon` yet",
		);
	}


	/**
	 * The questions of decisions the verified proposal leaves to the user that no call before found, the costliest
	 * first: at most `MaxDecisions` of them the first time, every one later, as it follows from the answers given.
	 * @return list<Question>
	 */
	public function findNext(Proposal $proposal): array
	{
		$first = !$this->found;
		$questions = array_diff_key([
			...self::findOverBudget($proposal),
			...self::findConflicts($proposal, $this->formatterRules),
			...self::findUnfixable($proposal),
		], $this->found);
		uasort($questions, fn(Question $a, Question $b) => $b->files <=> $a->files);
		$this->found += array_fill_keys(array_keys($questions), true);
		return array_values($first ? array_slice($questions, 0, self::MaxDecisions) : $questions);
	}


	/**
	 * A value the code or the standard holds to whose fix would rewrite more files than the budget allows.
	 * @return array<string, Question>  by the path of the decision
	 */
	private static function findOverBudget(Proposal $proposal): array
	{
		$questions = [];
		foreach ($proposal->values as $path => $proposed) {
			if ($proposed->origin === ValueOrigin::OverBudget) {
				$wanted = $proposal->standard->formatValue($path, $proposed->wanted);
				$questions[$path] = new Question(
					"Check `$path: $wanted`? A fix rewrites $proposed->cost files, more than the budget allows.",
					[
						new Answer('k', "don't check it"),
						new Answer('f', "$wanted everywhere, a fix rewrites $proposed->cost files", [$path => $proposed->wanted]),
					],
					"`$path: $wanted` in `dresscode.neon` checks it too; its fix changes $proposed->cost files",
					$proposed->example,
					$proposed->cost,
				);
			}
		}

		return $questions;
	}


	/**
	 * A value the code holds to that the configuration of the formatter contradicts.
	 * @return array<string, Question>  by the path of the decision
	 */
	private static function findConflicts(Proposal $proposal, ?Translation $formatterRules): array
	{
		$config = $proposal->project->formatterConfig;
		if ($formatterRules === null || $config === null) {
			return [];
		}

		$questions = [];
		foreach ($formatterRules->collectDecisions() as $path => $foreign) {
			$proposed = $proposal->values[$path] ?? null;
			$decision = $proposal->standard->decisions[$path]->decision ?? null;
			if ($proposed === null || $decision === null || $proposed->origin !== ValueOrigin::Measured || $proposed->isKept()) {
				continue;
			}

			try {
				if ($decision->accept($foreign)->toData() === $decision->accept($proposed->value)->toData()) {
					continue;
				}
			} catch (ConfigurationException) {
				continue;
			}

			$files = $proposal->estimateFiles($path, $foreign);
			if ($files !== null) {
				$questions[$path] = self::createConflict($proposal, $config, $path, $foreign, $files);
			}
		}

		return $questions;
	}


	private static function createConflict(Proposal $proposal, FormatterConfig $config, string $path, mixed $foreign, int $files): Question
	{
		$proposed = $proposal->values[$path];
		$theirs = $proposal->standard->formatValue($path, $foreign);
		$ours = $proposal->standard->formatValue($path, $proposed->value);
		$about = $proposal->isSampled() ? 'about ' : '';
		return new Question(
			"`$config->file` wants `$path: $theirs`, your code writes $ours ($proposed->comment); which should the file say?",
			[
				new Answer('c', "$ours, as your code writes it", [$path => $proposed->value], $proposed->comment),
				new Answer('x', "$theirs, as `$config->file` says, a fix rewrites $about" . self::formatFiles($files), [$path => $foreign]),
			],
			"`$path: $theirs` in `dresscode.neon` says what `$config->file` says; its fix changes $about" . self::formatFiles($files),
			files: $files,
		);
	}


	/**
	 * Names written in another case that share a prefix or a suffix, which `naming.except` may let pass.
	 * @return array<string, Question>  by the path of the decision
	 */
	private static function findUnfixable(Proposal $proposal): array
	{
		$verification = $proposal->verification ?? throw new \LogicException('The proposal is not verified.');
		$questions = [];
		$codes = [];
		foreach (NameCasingRule::getDecisions() as $decision) {
			$path = $decision->path;
			$names = $files = [];
			foreach ($verification->result->findings[$path] ?? [] as [$file, , , $fixable, $span]) {
				if ($fixable || $span === null) {
					continue;
				}

				// what the rule reports begins with the name, a constant going on with its value
				$codes[$file] ??= (string) @file_get_contents($proposal->project->root . "/$file"); // @ file may have been deleted meanwhile
				if (preg_match('~\$?(\w+)~A', substr($codes[$file], ...$span), $m)) {
					$names[] = $m[1];
					$files[$file] = true;
				}
			}

			$pattern = count($names) < self::MinNames ? null : self::findPattern($names);
			if ($pattern !== null) {
				$questions[$path] = self::createUnfixable($proposal, $path, $names, $pattern, count($files));
			}
		}

		return $questions;
	}


	/**
	 * @param  list<string>  $names
	 * @param  array{string, string}  $pattern  the regular expression, what it allows
	 */
	private static function createUnfixable(Proposal $proposal, string $path, array $names, array $pattern, int $files): Question
	{
		[$regex, $allows] = $pattern;
		$kind = substr($path, strlen('naming.'));
		$except = (array) ($proposal->values['naming.except']->value ?? $proposal->standard->decisions['naming.except']->value->toData() ?? []);
		return new Question(
			sprintf('%d %s names %s, which no rule renames; how should they be checked?', count($names), $kind, $allows),
			[
				new Answer(
					'e',
					"allow names that $allows",
					['naming.except' => [...$except, $regex]],
					sprintf('the %d %s names that %s, which no rule renames', count($names), $kind, $allows),
				),
				new Answer('b', 'list them in the baseline'),
				new Answer('k', "don't check $kind names", [$path => 'keep']),
			],
			"`naming.except` lets the $kind names that $allows pass; without it they go to the baseline",
			$proposal->verification?->result->findExample($path),
			$files,
		);
	}


	/**
	 * The suffix or the prefix every name shares, cut at the boundary of a word, as the regular expression of
	 * `naming.except` and what it allows: names that "end with `_`".
	 * @param  list<string>  $names
	 * @return ?array{string, string}
	 */
	private static function findPattern(array $names): ?array
	{
		if (!$names) {
			return null;
		}

		$shortest = min(array_map(strlen(...), $names));
		$suffix = $prefix = '';
		for ($i = 1; $i < $shortest; $i++) {
			$tail = substr($names[0], -$i);
			if (!array_all($names, fn(string $name) => str_ends_with($name, $tail))) {
				break;
			} elseif (array_all($names, fn(string $name) => self::isWordBoundary($name, strlen($name) - $i))) {
				$suffix = $tail;
			}
		}

		for ($i = 1; $i < $shortest; $i++) {
			$head = substr($names[0], 0, $i);
			if (!array_all($names, fn(string $name) => str_starts_with($name, $head))) {
				break;
			} elseif (array_all($names, fn(string $name) => self::isWordBoundary($name, $i))) {
				$prefix = $head;
			}
		}

		return match (true) {
			strlen($suffix) >= strlen($prefix) && $suffix !== '' => ['~' . preg_quote($suffix, '~') . '$~', "end with `$suffix`"],
			$prefix !== '' => ['~^' . preg_quote($prefix, '~') . '~', "start with `$prefix`"],
			default => null,
		};
	}


	private static function formatFiles(int $count): string
	{
		return match ($count) {
			0 => 'no file',
			1 => '1 file',
			default => "$count files",
		};
	}


	/** Whether a word of the name ends before the offset: at an underscore or where a capital follows a lowercase letter. */
	private static function isWordBoundary(string $name, int $at): bool
	{
		return $name[$at - 1] === '_' || $name[$at] === '_' || (ctype_upper($name[$at]) && !ctype_upper($name[$at - 1]));
	}
}
