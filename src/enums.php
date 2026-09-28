<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode;


/**
 * Stage of a pass: structural changes come first, formatting second, the finishing of comments, doc comments
 * and the whitespace of lines last.
 */
enum Stage
{
	case Structure;
	case Formatting;
	case Finishing;
}


/**
 * The answer to a question the code may not settle.
 */
enum Tristate
{
	case Yes;
	case No;
	case Maybe;
}


/**
 * Why the fix of an occurrence may change what the code does: what would decide that it does not.
 */
enum Risk: string
{
	/** the type of a value, which neither the declarations in sight nor the types tell */
	case TypeUnknown = 'typeUnknown';

	/** whether an unqualified name reaches a function or a constant of the namespace, which `nameResolution: certain` tells */
	case NameUncertain = 'nameUncertain';

	/** a human: changing what the code does is what the fix is for, or it depends on code the run does not see */
	case BehaviorChanges = 'behaviorChanges';


	/**
	 * Whether what the project can tell the tool, the types or a certain resolution of names, would decide that the
	 * fix keeps what the code does, as opposed to a human.
	 */
	public function isResolvable(): bool
	{
		return match ($this) {
			self::TypeUnknown, self::NameUncertain => true,
			self::BehaviorChanges => false,
		};
	}
}


enum Severity: string
{
	case Error = 'error';
	case Warning = 'warning';
}


/**
 * What a decision is to its rule.
 */
enum DecisionKind: string
{
	/** turns its rule on wherever it is not `keep` */
	case Requirement = 'requirement';

	/** only refines a requirement and turns nothing on, so it takes no `keep` and has a default */
	case Parameter = 'parameter';

	/** a key of the environment the rule guards, which `--only` never narrows away */
	case Fact = 'fact';
}


/**
 * What a gap rule asks for between two tokens sharing a line.
 */
enum Space
{
	case None;
	case Single;

	/** a single space, or whitespace holding a tab, which aligns columns */
	case SingleOrTabs;

	/** one or more spaces, no tab */
	case AtLeastOne;

	case AtLeastOneOrTabs;
}


/**
 * Which line a gap rule asks the second of two tokens to stand on.
 */
enum Line
{
	case Same;
	case Next;
}
