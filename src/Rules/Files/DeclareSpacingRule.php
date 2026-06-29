<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Claim, Decision, GapRule, RuleInfo, Stage};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\DeclareItemNode;
use PhpSyntax\Nodes\Statement\DeclareNode;


/**
 * No whitespace inside a `declare` statement: `declare(strict_types=1);`.
 */
#[RuleInfo(Stage::Formatting)]
final class DeclareSpacingRule extends GapRule
{
	public static function getDecisions(): array
	{
		return [new Decision('spacing.declare', new Shapes(['compact' => ['declare(strict_types=1)', 'none inside']]), 'The whitespace inside a `declare` statement')];
	}


	public function getClaims(): array
	{
		return [
			DeclareNode::class => [
				'declareKeyword' => [null, Claim::noSpace()],
				'openParen' => [null, Claim::noSpace()],
				'closeParen' => [Claim::noSpace(), null],
			],
			DeclareItemNode::class => [
				'name' => [null, Claim::noSpace()],
				'equals' => [Claim::noSpace(), Claim::noSpace()],
			],
		];
	}
}
