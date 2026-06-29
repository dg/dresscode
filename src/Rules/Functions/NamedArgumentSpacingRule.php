<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Claim, Decision, GapRule, Line, RuleInfo, Space, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\{ArgumentNode, ArgumentPlaceholderNode};


/**
 * A named argument as `name: $value` and a named placeholder as `name: ?` on one line: no whitespace before the
 * colon, a single space after it.
 */
#[RuleInfo(Stage::Formatting)]
final class NamedArgumentSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.namedArgument', new Shapes(['spacedAfter' => ['foo(name: $x)', 'none before the colon, a single space after']]), 'The whitespace around the colon of a named argument')];
	}


	public function getClaims(): array
	{
		$claims = [
			'name' => [null, new Claim(Space::None, line: Line::Same)],
			'colon' => [null, new Claim(Space::Single, line: Line::Same)],
		];
		return [
			ArgumentNode::class => $claims,
			ArgumentPlaceholderNode::class => $claims,
		];
	}
}
