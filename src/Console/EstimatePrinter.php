<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console;

use DressCode\Upgrade\{Blocker, Blockers, Estimate, Fixability, Phase, Target, Topic};
use Nette\CommandLine\Console;
use function count, sprintf, strlen;


/**
 * Writes the offer of `dresscode upgrade`: what must change for the targets and what may, topic by topic with what the
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

	/** the groups of the topics by the sets they are of */
	private const Groups = ['Must change' => ['deprecations', 'cleanup'], 'May modernize' => ['modernizations']];

	/** what the run can do about a place, as the output says it */
	private const FixabilityWords = [
		'automatic' => 'automatic',
		'unknownTypes' => 'unknown types',
		'uncertainNames' => 'uncertain names',
		'manual' => 'for you',
	];


	public function __construct(
		private Estimate $estimate,
	) {
	}


	public function print(Console $console): string
	{
		$estimate = $this->estimate;
		$label = fn(string $label) => $console->color('gray', str_pad($label, 11));
		$out = $label('Upgrade') . implode(', ', array_map(fn(Target $target) => $target->format(), $estimate->targets))
			. ' over ' . Markup::formatCount($estimate->files, 'file')
			. ($estimate->namesMeasured ? ', the names resolved for certain as measured' : '')
			. ($estimate->types ? '' : ', without the types of the code') . "\n\n";

		$width = max([0, ...array_map(fn(Topic $topic) => strlen($topic->name), $estimate->topics)]);
		foreach (self::Groups as $title => $sets) {
			$topics = array_values(array_filter($estimate->topics, fn(Topic $topic) => in_array($topic->set, $sets, true)));
			if ($topics === []) {
				continue;
			}

			$places = array_sum(array_map(fn(Topic $topic) => $topic->countPlaces(), $topics));
			$out .= $console->color('white', $title) . ', ' . Markup::formatCount(count($topics), 'topic')
				. ' in ' . Markup::formatCount($places, 'place') . "\n";
			foreach ($topics as $topic) {
				$out .= sprintf(
					"  %s  %s  %s, %s\n",
					str_pad($topic->name, $width),
					str_pad(Markup::formatCount($topic->countPlaces(), 'place') . ' in ' . Markup::formatCount($topic->files, 'file'), 22),
					$console->color(...match ($topic->phase) {
						Phase::Bridge => ['green', 'works today'],
						Phase::Jump => ['yellow', 'after the raise'],
					}),
					self::formatPlaces($topic),
				);
			}

			$out .= "\n";
		}

		if ($estimate->topics === []) {
			$out .= "Nothing to upgrade: no rule and no upgrading data find anything for the targets.\n\n";
		}

		$today = array_sum(array_map(
			fn(Topic $topic) => $topic->countPlaces(Fixability::Automatic),
			$estimate->findTopics(Phase::Bridge, 'deprecations'),
		));
		if ($today > 0) {
			$out .= $label('Today') . Markup::formatCount($today, 'place')
				. ' of `deprecations` ' . ($today === 1 ? 'is' : 'are') . " rewritten on the versions allowed now by `dresscode upgrade --today`\n";
		}

		if (!$estimate->blindSpots->isEmpty()) {
			$out .= $label("Can't see") . $this->formatBlindSpots() . "\n";
		}

		foreach ($estimate->blockers as $blockers) {
			$out .= $label('Blocked') . $blockers->target->format() . ': ' . self::formatBlockers($blockers) . "\n";
		}

		foreach ($estimate->missingData as $package => $libraries) {
			$out .= $label('No data') . self::formatList($libraries)
				. ": the upgrading data are in `$package`, which `dresscode upgrade --today` installs\n";
		}

		$out .= $label('Data') . (implode(', ', array_map(
			fn(string $library, string $version) => "$library up to $version",
			array_keys($estimate->knownVersions),
			$estimate->knownVersions,
		)) ?: 'no upgrading data reach the project') . "\n";
		foreach ($estimate->warnings as $warning) {
			$out .= "\n" . Markup::highlightCode($console, "Warning: $warning", 'yellow');
		}

		return $out . ($estimate->warnings === [] ? '' : "\n");
	}


	/**
	 * The offer as the data the format `json` writes.
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
				'files' => $topic->files,
				'places' => ['total' => $topic->countPlaces(), ...$topic->places],
			], $estimate->topics),
			'blindSpots' => [...(array) $estimate->blindSpots, 'otherFiles' => (object) $estimate->blindSpots->otherFiles],
			'blockers' => array_map(fn(Blockers $blockers) => [
				'target' => (array) $blockers->target,
				'packages' => array_map(fn(Blocker $blocker) => (array) $blocker, $blockers->packages),
				'unknown' => $blockers->unknown,
			], $estimate->blockers),
			'missingData' => (object) $estimate->missingData,
			'knownVersions' => (object) $estimate->knownVersions,
			'warnings' => $estimate->warnings,
		];
	}


	/** What the run can do about the places of the topic: `automatic`, or the count of each. */
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
				? Markup::formatCount($spots->classNameStrings, 'string') . ' naming a class the data rename or forbid'
				: null,
			$spots->dynamicAccesses ? Markup::formatCount($spots->dynamicAccesses, 'place') . ' reached by a computed name' : null,
			$spots->otherFiles
				? implode(', ', array_map(
					fn(string $extension, int $count) => Markup::formatCount($count, ".$extension file"),
					array_keys($spots->otherFiles),
					$spots->otherFiles,
				)) . ', which no rule reads'
				: null,
			$spots->generatedFiles ? Markup::formatCount($spots->generatedFiles, 'generated file') . ', left out' : null,
		]));
	}


	/** The packages refusing the target, or why nothing is known of them. */
	private static function formatBlockers(Blockers $blockers): string
	{
		return match (true) {
			$blockers->unknown !== null => "unknown, $blockers->unknown",
			$blockers->packages === [] => 'nothing in the lock refuses it',
			default => self::formatList(array_map(
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
}
