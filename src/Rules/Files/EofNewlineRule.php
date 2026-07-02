<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Claim;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Stage;
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
