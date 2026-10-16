<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Arrays;

use DressCode\{Claim, Decision, Gap, GapRule, RuleInfo, Stage, Values};
use DressCode\Rules\Whitespace\Padding;
use PhpSyntax\Nodes\DestructuringNode;
use PhpSyntax\Nodes\Expression\ArrayNode;


/**
 * No whitespace just inside the brackets of an array or a destructuring on a line: `[1, 2]`, `[$a, $b] = $x` and
 * `array(1)`, not `[ 1, 2 ]`, or with `required` a single space inside those holding something, `[ 1, 2 ]` and `[]`.
 */
#[RuleInfo(Stage::Formatting)]
final class ArraySpacingRule extends GapRule
{
	private const Array = 'spacing.padding.array';
	private const List = 'spacing.padding.list';

	/** @var array{Claim|\Closure(Gap): Claim|null, Claim|\Closure(Gap): Claim|null}  the claims inside an array */
	private array $array = [null, null];

	/** @var array{Claim|\Closure(Gap): Claim|null, Claim|\Closure(Gap): Claim|null}  the claims inside a destructuring */
	private array $list = [null, null];


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Array, Padding::createDomain(), 'The whitespace inside the brackets or the parentheses of an array'),
			new Decision(self::List, Padding::createDomain(), 'The whitespace inside the brackets or the parentheses of a destructuring'),
		];
	}


	public function configure(Values $values): void
	{
		$this->array = Padding::createClaims(self::Array, $values->isKept(self::Array) ? null : $values->get(self::Array)->getWord());
		$this->list = Padding::createClaims(self::List, $values->isKept(self::List) ? null : $values->get(self::List)->getWord());
	}


	public function getClaims(): array
	{
		return [
			ArrayNode::class => [
				'openDelimiter' => [null, $this->array[0]],
				'closeDelimiter' => [$this->array[1], null],
			],
			DestructuringNode::class => [
				'openDelimiter' => [null, $this->list[0]],
				'closeDelimiter' => [$this->list[1], null],
			],
		];
	}
}
