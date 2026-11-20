<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Engine\Helpers;
use DressCode\Upgrade\{Blocker, Blockers, Estimate, Fixability, Move, Phase, Target, Topic};
use Nette\CommandLine\Console;
use function count, sprintf, strlen;


/**
 * Writes the estimate of `dresscode upgrade estimate`: what must change for the targets and what may, topic by topic with what the
 * run can do about the places, what works on the versions allowed today, what the upgrade does not see, what refuses
 * the targets, and how far the upgrading data reach.
 * @internal
 */
final readonly class EstimatePrinter
{
	/** the version of the document the format `json` writes */
	public const Version = 1;

	/** the names of a list the output writes one by one, the rest only counted */
	private const ListedNames = 8;

	/** the width the lines of the output keep, which a narrow terminal and a page of the manual show unbroken */
	private const Width = 100;

	/** the groups of the topics by the sets they are of */
	private const Groups = ['Must change' => ['deprecations', 'cleanup'], 'May modernize' => ['modernizations']];

	/** what the run can do about a finding, as the output says it */
	private const FixabilityWords = [
		'automatic' => 'fixed by DressCode',
		'typeUnknown' => 'waiting for the types',
		'nameUncertain' => 'waiting for certain names',
		'manual' => 'left to you',
	];


	public function __construct(
		private Estimate $estimate,
	) {
	}


	/** @param  bool  $passive  no step of the run follows that would change the code */
	public function print(Console $console, bool $passive = true): string
	{
		$estimate = $this->estimate;
		$targets = implode(', ', array_map(fn(Target $target) => $target->format(), $estimate->targets));
		$out = Markup::formatLine(
			$console,
			'Upgrade',
			"$targets over " . Helpers::formatCount($estimate->files, 'file'),
			($estimate->namesMeasured ? ', the names resolved for certain as measured' : '')
			. ($estimate->types ? '' : ', without the types of the code')
			. ($passive ? ', an estimate: nothing was changed' : ''),
		) . "\n\n";

		$width = max([0, ...array_map(fn(Topic $topic) => strlen($topic->name), $estimate->topics)]);
		foreach (self::Groups as $title => $sets) {
			$topics = array_values(array_filter($estimate->topics, fn(Topic $topic) => in_array($topic->set, $sets, true)));
			if ($topics === []) {
				continue;
			}

			$places = array_sum(array_map(fn(Topic $topic) => $topic->countPlaces(), $topics));
			$automatic = array_all(
				$topics,
				fn(Topic $topic) => array_keys(array_filter($topic->places)) === [Fixability::Automatic->value],
			);
			$out .= $console->color('white', $title) . ', ' . Helpers::formatCount($places, 'finding')
				. ($automatic ? ', all fixed by DressCode' : '') . "\n";
			foreach ($topics as $topic) {
				$out .= sprintf(
					"  %s  %s  %s, %s\n",
					str_pad($topic->name, $width),
					str_pad(
						Helpers::formatCount($topic->countPlaces(), 'finding') . ' in ' . Helpers::formatCount($topic->files, 'file'),
						24,
					),
					self::formatPlaces($topic),
					$console->color(...match ($topic->phase) {
						Phase::Bridge => ['green', $topic->constraint === null ? 'already today' : "already on $topic->constraint"],
						Phase::Jump => ['yellow', $topic->constraint === null ? 'after the raise' : "once $topic->constraint is installed"],
					}),
				);
			}

			$out .= "\n";
		}

		if ($estimate->topics === []) {
			$out .= $estimate->isComplete()
				? "Nothing to upgrade: no rule and no upgrading data find anything for the targets.\n\n"
				: "Nothing found for the targets in what the check could read, which the lines below say is not all.\n\n";
		}

		$today = array_sum(array_map(
			fn(Topic $topic) => $topic->countPlaces(Fixability::Automatic),
			$estimate->findTopics(Phase::Bridge, 'deprecations'),
		));
		if ($today > 0) {
			$out .= Markup::formatLine(
				$console,
				'Right now',
				"`dresscode upgrade today` can rewrite $today of these",
				', on the versions you have',
			) . "\n";
		}

		if (!$estimate->blindSpots->isEmpty()) {
			$out .= Markup::formatLine($console, "Can't see", $this->formatBlindSpots()) . "\n";
		}

		if ($estimate->unprocessed !== []) {
			$out .= Markup::formatLine(
				$console,
				'Unverified',
				Helpers::formatCount(count($estimate->unprocessed), 'file') . ' the check could not process',
				', whose findings are not counted',
				': ' . self::formatList(array_keys($estimate->unprocessed)),
			) . "\n";
		}

		foreach ($estimate->blockers as $blockers) {
			$out .= Markup::formatLine($console, 'Composer', $blockers->target->format() . ': ' . self::formatBlockers($blockers)) . "\n";
		}

		$major = $estimate->moves?->findMajor() ?? [];
		$moves = match (true) {
			$estimate->library => 'nothing, a library widens its constraints with `--no-update`',
			$estimate->moves?->unknown !== null => "unknown, {$estimate->moves->unknown}",
			$major !== [] => self::wrapList(array_map(
				fn(Move $move) => "`$move->package` $move->from => $move->to"
					. match (true) {
						$move->isKnown($estimate->knownVersions) => '',
						$move->isSeriesKnown($estimate->knownVersions) => " (data up to {$estimate->knownVersions[$move->package]})",
						default => ' (no data)',
					},
				$major,
			)),
			default => null,
		};
		if ($moves !== null) {
			$out .= Markup::formatLine($console, 'Versions', $moves) . "\n";
		}

		foreach ($estimate->missingData as $package => $libraries) {
			$out .= Markup::formatLine(
				$console,
				'No data',
				self::formatList(array_map(fn(string $library) => "`$library`", $libraries)),
				": the upgrading data are in `$package`, which `dresscode upgrade today` offers to install, with `--yes` without asking",
			) . "\n";
		}

		$out .= Markup::formatLine($console, 'Rules for', self::wrapList(array_map(
			fn(string $library, string $version) => "`$library` up to $version",
			array_keys($estimate->knownVersions),
			$estimate->knownVersions,
		)) ?: 'no upgrading data reach the project') . "\n";
		foreach ($estimate->warnings as $warning) {
			$out .= "\n" . Markup::highlightCode($console, "Warning: $warning", 'yellow');
		}

		return $out . ($estimate->warnings === [] ? '' : "\n");
	}


	/**
	 * The estimate as the data the format `json` writes.
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$estimate = $this->estimate;
		return [
			'version' => self::Version,
			'targets' => array_map(fn(Target $target) => (array) $target, $estimate->targets),
			'files' => $estimate->files,
			'namesMeasured' => $estimate->namesMeasured,
			'types' => $estimate->types,
			'topics' => array_map(fn(Topic $topic) => [
				'name' => $topic->name,
				'set' => $topic->set,
				'phase' => $topic->phase->value,
				'constraint' => $topic->constraint,
				'files' => $topic->files,
				'findings' => ['total' => $topic->countPlaces(), ...$topic->places],
			], $estimate->topics),
			'blindSpots' => [...(array) $estimate->blindSpots, 'otherFiles' => (object) $estimate->blindSpots->otherFiles],
			'blockers' => array_map(fn(Blockers $blockers) => [
				'target' => (array) $blockers->target,
				'packages' => array_map(fn(Blocker $blocker) => (array) $blocker, $blockers->packages),
				'unknown' => $blockers->unknown,
			], $estimate->blockers),
			'moves' => $estimate->moves === null ? null : [
				'packages' => array_map(fn(Move $move) => [
					...(array) $move,
					'major' => $move->isMajor(),
					'data' => $move->isKnown($estimate->knownVersions),
					'dataUpTo' => $estimate->knownVersions[$move->package] ?? null,
				], $estimate->moves->packages),
				'unknown' => $estimate->moves->unknown,
			],
			'library' => $estimate->library,
			'unverified' => TasksPrinter::listUnverified($estimate->unprocessed),
			'missingData' => (object) $estimate->missingData,
			'knownVersions' => (object) $estimate->knownVersions,
			'warnings' => $estimate->warnings,
		];
	}


	/** What becomes of the findings of the topic: all fixed by DressCode, or the count of each. */
	private static function formatPlaces(Topic $topic): string
	{
		$places = array_filter($topic->places);
		return array_keys($places) === [Fixability::Automatic->value]
			? self::FixabilityWords[Fixability::Automatic->value]
			: implode(', ', array_map(
				fn(string $fixability, int $count) => "$count " . self::FixabilityWords[$fixability],
				array_keys($places),
				$places,
			));
	}


	/** What the upgrade does not see, each kind of it counted. */
	private function formatBlindSpots(): string
	{
		$spots = $this->estimate->blindSpots;
		return implode('; ', array_filter([
			$spots->classNameStrings
				? Helpers::formatCount($spots->classNameStrings, 'string') . ' naming a class the data rename or forbid'
				: null,
			$spots->dynamicAccesses ? Helpers::formatCount($spots->dynamicAccesses, 'place') . ' reached by a computed name' : null,
			$spots->otherFiles
				? implode(', ', array_map(
					fn(string $extension, int $count) => Helpers::formatCount($count, ".$extension file"),
					array_keys($spots->otherFiles),
					$spots->otherFiles,
				)) . ', which no rule reads'
				: null,
			$spots->generatedFiles ? Helpers::formatCount($spots->generatedFiles, 'generated file') . ', left out' : null,
		]));
	}


	/** The packages refusing the target, or why nothing is known of them. */
	public static function formatBlockers(Blockers $blockers): string
	{
		return match (true) {
			$blockers->unknown !== null => "unknown, $blockers->unknown",
			$blockers->packages === [] => 'nothing in the lock refuses it',
			default => 'refused by ' . self::formatList(array_map(
				fn(Blocker $blocker) => "$blocker->package $blocker->version ($blocker->constraint)",
				$blockers->packages,
			)),
		};
	}


	/**
	 * The first names of the list, the rest counted.
	 * @param  list<string>  $names
	 */
	private static function formatList(array $names): string
	{
		return implode(', ', array_slice($names, 0, self::ListedNames))
			. (count($names) > self::ListedNames ? ' and ' . (count($names) - self::ListedNames) . ' more' : '');
	}


	/**
	 * The items separated by commas, broken onto lines that keep the width of the output, each under the value.
	 * @param  list<string>  $items
	 */
	private static function wrapList(array $items): string
	{
		$lines = [];
		$line = '';
		foreach ($items as $i => $item) {
			$item .= $i < count($items) - 1 ? ',' : '';
			if ($line !== '' && strlen("$line $item") > self::Width - Markup::LabelWidth) {
				$lines[] = $line;
				$line = $item;
			} else {
				$line = $line === '' ? $item : "$line $item";
			}
		}

		return implode("\n" . str_repeat(' ', Markup::LabelWidth), [...$lines, $line]);
	}
}
