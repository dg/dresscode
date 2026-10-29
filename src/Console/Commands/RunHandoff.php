<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Console\CommandContext;
use DressCode\Engine\Helpers;
use DressCode\Upgrade\{DependencyStep, Plan, RunOutcome, Step};
use Nette\Utils\FileSystem;
use function count;


/**
 * How a run of the plan goes on after a raise of Composer in a new process, which loads what the raise installed: the
 * run before it hands the outcome over in a file the environment names, and the new one hands back where it stopped.
 * @internal
 */
final readonly class RunHandoff
{
	/** the variable of the environment naming the file of the handoff */
	private const Variable = 'DRESSCODE_UPGRADE_HANDOFF';


	private function __construct(
		/** the file to hand the outcome back in; null for a run the user started */
		private ?string $file,
		/** where the run before the raise stopped */
		public ?RunOutcome $resumed,
	) {
	}


	/** The handoff this process was started with, taken out of the environment so that no process it starts reads it. */
	public static function take(): self
	{
		$file = getenv(self::Variable) ?: null;
		putenv(self::Variable);
		if ($file === null) {
			return new self(null, null);
		}

		$resumed = self::read($file);
		FileSystem::delete($file);
		return new self($file, $resumed);
	}


	/** Hands the outcome back to the run that started this one, which goes on from it. */
	public function handBack(RunOutcome $outcome): void
	{
		$file = $this->file ?? throw new \LogicException('The user started the run, so there is no run to hand back to.');
		Helpers::replaceFile($file, json_encode($outcome->toArray(), JSON_THROW_ON_ERROR));
	}


	/**
	 * Goes on after the raise in a new process as often as the plan has steps of Composer, each process handing back
	 * where it stopped at the next raise; null where this process starts none, as an embedded one.
	 * @param  list<string>  $rerun  the command line of the run
	 */
	public static function goOn(CommandContext $context, RunOutcome $outcome, Plan $plan, array $rerun): ?int
	{
		$file = sys_get_temp_dir() . '/dresscode-handoff-' . bin2hex(random_bytes(8)) . '.json';
		$raises = count(array_filter($plan->findSteps(), fn(Step $step) => $step instanceof DependencyStep));
		try {
			while ($raises-- > 0) {
				Helpers::replaceFile($file, json_encode($outcome->toArray(), JSON_THROW_ON_ERROR));
				$exit = $context->rerun($rerun, [self::Variable => $file]);
				if ($exit === null || !is_file($file)) { // the run cannot go on here, or went on to its end
					return $exit;
				}

				$outcome = self::read($file);
			}
		} finally {
			@unlink($file); // @ the last run took it
		}

		throw new \LogicException('The plan raised more often than it has steps of Composer.');
	}


	private static function read(string $file): RunOutcome
	{
		return RunOutcome::fromArray((array) json_decode(FileSystem::read($file), associative: true, flags: JSON_THROW_ON_ERROR));
	}
}
