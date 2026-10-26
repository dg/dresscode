<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Violation;
use Nette\CommandLine\Attributes as Cli;
use Nette\Neon\{Exception as NeonException, Neon};
use function count;


/**
 * The configuration the command line names and what it lays over it, which every command reading the configuration shares.
 * @internal
 */
final readonly class ConfigOptions
{
	public function __construct(
		#[Cli\Option('Configuration file; the nearest `dresscode.neon` or `dresscode.php` when omitted', alias: '-c', valueName: 'file')]
		public ?string $config = null,
		/** @var list<string> */
		#[Cli\Option(
			'Add a preset or a plugin over the configuration, even without a configuration file',
			valueName: 'name',
			repeatable: true,
			normalizer: [self::class, 'checkPreset'],
		)]
		public array $use = [],
		/** @var list<string> */
		#[Cli\Option(
			'Set a decision over the configuration as `path=value`, the value in NEON: `blankLines.aroundMethods=1`, `spacing.arrowFunction="fn ($x) => $x"`',
			valueName: 'path=value',
			repeatable: true,
			normalizer: [self::class, 'checkDecision'],
		)]
		public array $set = [],
		/** @var list<string> */
		#[Cli\Option(
			'Say how the violations of a decision, a section, a preset or a rule count as `name=warning` or `name=error`',
			valueName: 'name=severity',
			repeatable: true,
			normalizer: [self::class, 'checkSeverity'],
		)]
		public array $severity = [],
		/** the root of the run in place of the directory of the configuration file, for a configuration written elsewhere */
		#[Cli\Option(hidden: true)]
		public ?string $root = null,
	) {
	}


	/**
	 * The normalizer of `--use`, which names a preset or a plugin, not a decision.
	 */
	public static function checkPreset(string $spec): string
	{
		return str_contains($spec, '=')
			? throw new \InvalidArgumentException('expects a preset or a plugin, ' . Violation::formatCode($spec) . ' given; a decision is set by `--set path=value`.')
			: $spec;
	}


	/**
	 * The normalizer of `--set`, which takes a decision as `path=value`, the value in NEON the way the configuration
	 * reads it; the decision stays as given, CommandContext::readCommandLine() places it.
	 */
	public static function checkDecision(string $spec): string
	{
		$parts = explode('=', $spec, 2);
		if (count($parts) !== 2 || !preg_match('~^\w+(\.\w+)+$~D', $parts[0])) {
			throw new \InvalidArgumentException('expects `path=value`, ' . Violation::formatCode($spec) . ' given.');
		}

		try {
			Neon::decode($parts[1]);
		} catch (NeonException $e) {
			throw new \InvalidArgumentException(
				'expects the value in NEON, ' . Violation::formatCode($spec) . " given: {$e->getMessage()}",
				previous: $e,
			);
		}

		return $spec;
	}


	/** The normalizer of `--severity`, which takes a name of a decision, a section, a preset or a rule as `name=warning`. */
	public static function checkSeverity(string $spec): string
	{
		return preg_match('~^[^=]+=(warning|error)$~D', $spec)
			? $spec
			: throw new \InvalidArgumentException('expects `name=warning` or `name=error`, ' . Violation::formatCode($spec) . ' given.');
	}
}
