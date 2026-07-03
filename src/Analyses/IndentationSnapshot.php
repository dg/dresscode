<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Analyses;

use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Token;


/**
 * Whether a line has the indentation the run gives it, as the plan of the indentation said at the first question in
 * the pass, which a rule deciding by the width of a line waits for. The lines are known by the tokens opening them,
 * because the breaks of the pass move their numbers, and a line opened in the pass is taken as in place, the engine
 * giving it the indentation it infers. A break makes the lines below it no shallower, so the snapshot outlives the
 * breaks of the pass, while a plan computed anew after every break of a file with many long lines would cost a walk
 * over the file each; a line the engine joins may leave the lines below it shallower, so a join drops the snapshot
 * and the next question takes a new one.
 */
final class IndentationSnapshot implements PassAnalysis
{
	/** @var \WeakMap<Token, bool>  the token opening a line => whether the line was in place */
	private \WeakMap $openers;


	public function __construct(IndentationPlan $plan)
	{
		$this->openers = new \WeakMap;
		foreach ($plan->getPlacements() as $placement) {
			$this->openers[$placement->token] = $placement->isInPlace();
		}
	}


	/**
	 * The factory a registry of analyses builds it with, from the factory of the plan.
	 * @param  \Closure(FileNode): IndentationPlan  $planFactory
	 * @return \Closure(FileNode): self
	 */
	public static function createFactory(\Closure $planFactory): \Closure
	{
		return fn(FileNode $file) => new self($planFactory($file));
	}


	/** Whether the line the token stands on was in place when the pass asked first, or was opened since. */
	public function isLineInPlace(Token $token): bool
	{
		$opener = $token;
		while (!$opener->startsLine() && ($previous = $opener->getPrevious()) !== null) {
			$opener = $previous;
		}

		return $this->openers[$opener] ?? true;
	}
}
