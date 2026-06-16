<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * A rule inspects and fixes one aspect of the code. It is stateless across files: an instance serves the whole
 * run. Every mutation of the tree must follow a `report()`
 * that returned true. A rule is a NodeRule, visiting the nodes and tokens it names, or a GapRule, claiming
 * what the gaps between tokens must be.
 */
abstract class Rule
{
	/**
	 * What the rule decides itself; with the decisions of the tree of the core it names in `RuleInfo::$decisions`, one
	 * decision at least. It runs where any decision that is a requirement is not `keep`.
	 * @return list<Decision>
	 */
	public static function getDecisions(): array
	{
		return [];
	}


	/** The values of its decisions, and of any other it reads, for the files the run builds it for. */
	public function configure(Values $values): void
	{
	}
}
