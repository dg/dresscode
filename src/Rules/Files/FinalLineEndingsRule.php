<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Stage};
use DressCode\Domains\Count;
use PhpSyntax\Nodes\FileNode;


/**
 * The file ends with exactly one line ending, after a trailing comment when there is one; a file ending
 * with a close tag or inline HTML is left alone.
 */
#[RuleInfo(Stage::Formatting)]
final class FinalLineEndingsRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('file.finalLineEndings', new Count(1, 1, range: false), 'Exactly one line ending after the last line; a file ending in HTML is content')];
	}


	public function getClaims(): array
	{
		return [FileNode::class => ['endOfFile' => [new Claim(line: Line::Next, blankLines: 0), null]]];
	}
}
