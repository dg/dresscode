<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Claim, GapRule, RuleInfo, Stage};
use PhpSyntax\Nodes\DeclareItemNode;
use PhpSyntax\Nodes\Statement\DeclareNode;


/**
 * No whitespace inside a declare statement: `declare(strict_types=1);`.
 */
#[RuleInfo(
	'dresscode/declare-spacing',
	Stage::Formatting,
	description: 'Removes whitespace inside a declare statement',
)]
final class DeclareSpacingRule extends GapRule
{
	public function getClaims(): array
	{
		return [
			DeclareNode::class => [
				'declareKeyword' => [null, Claim::none()],
				'openParen' => [null, Claim::none()],
				'closeParen' => [Claim::none(), null],
			],
			DeclareItemNode::class => [
				'name' => [null, Claim::none()],
				'equals' => [Claim::none(), Claim::none()],
			],
		];
	}
}
