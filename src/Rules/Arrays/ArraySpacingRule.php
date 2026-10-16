<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Claim, Decision, Domain, GapRule, RuleInfo, Stage, Values};
use PhpSyntax\Nodes\DestructuringNode;
use PhpSyntax\Nodes\Expression\ArrayNode;


/**
 * No whitespace just inside the brackets of an array or a destructuring on a line: `[1, 2]`, `[$a, $b] = $x` and
 * `array(1)`, not `[ 1, 2 ]`.
 */
#[RuleInfo(Stage::Formatting)]
final class ArraySpacingRule extends GapRule
{
	private const Array = 'spacing.padding.array';
	private const List = 'spacing.padding.list';

	private ?Claim $array = null;
	private ?Claim $list = null;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Array, Domain::state(), 'The whitespace inside the brackets or the parentheses of an array'),
			new Decision(self::List, Domain::state(), 'The whitespace inside the brackets or the parentheses of a destructuring'),
		];
	}


	public function configure(Values $values): void
	{
		$this->array = $values->isKept(self::Array) ? null : Claim::noSpace()->withDecision(self::Array);
		$this->list = $values->isKept(self::List) ? null : Claim::noSpace()->withDecision(self::List);
	}


	public function getClaims(): array
	{
		return [
			ArrayNode::class => [
				'openDelimiter' => [null, $this->array],
				'closeDelimiter' => [$this->array, null],
			],
			DestructuringNode::class => [
				'openDelimiter' => [null, $this->list],
				'closeDelimiter' => [$this->list, null],
			],
		];
	}
}
