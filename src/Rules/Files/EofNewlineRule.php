<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Claim, GapRule, Line, RuleInfo, Stage};
use PhpSyntax\Nodes\FileNode;


/**
 * The file ends with exactly one line ending, after a trailing comment when there is one; a file ending
 * with a close tag or inline HTML is left alone.
 */
#[RuleInfo(
	'dresscode/eof-newline',
	Stage::Formatting,
	description: 'Ends the file with exactly one line ending',
)]
final class EofNewlineRule extends GapRule
{
	public function getClaims(): array
	{
		return [FileNode::class => ['endOfFile' => [new Claim(line: Line::Next, blank: 0), null]]];
	}
}
