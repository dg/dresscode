<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\Whitespace\Padding;
use PhpSyntax\Nodes\Expression\ArrayAccessNode;
use PhpSyntax\Nodes\Scalar\{IntegerNode, StringNode};


/**
 * No whitespace before the brackets of an offset access and none inside them, which stay on one line: `$a[$i]`, not
 * `$a [ $i ]`; with `required` a single space inside those holding an index, `$a[ $i ]`, and with `exceptLiterals`
 * so except around a string or an integer literal, `$a['b']` and `$a[0]`.
 */
#[RuleInfo(Stage::Formatting)]
final class OffsetBracketSpacingRule extends GapRule
{
	private const Path = 'spacing.padding.offset';

	private string $word = 'forbidden';


	public static function getDecisions(): array
	{
		return [new Decision(
			self::Path,
			new Words([
				...Padding::createDomain()->words,
				'exceptLiterals' => 'a single space around an index other than a string or an integer literal, `$a[ $i ]` but `$a[0]`',
			]),
			'The whitespace inside the brackets of an offset access and before them',
		)];
	}


	public function configure(Values $values): void
	{
		$this->word = $values->find(self::Path)?->getWord() ?? 'forbidden';
	}


	public function getClaims(): array
	{
		$hug = new Claim(Space::None, line: Line::Same);
		[$open, $close] = Padding::createClaims(self::Path, $this->word === 'forbidden' ? 'forbidden' : 'required', Line::Same);
		if ($this->word === 'exceptLiterals') {
			$isLiteral = fn(Gap $gap) => $gap->token->parent instanceof ArrayAccessNode
				&& ($gap->token->parent->index instanceof StringNode || $gap->token->parent->index instanceof IntegerNode);
			[$open, $close] = [
				fn(Gap $gap) => $isLiteral($gap) ? $hug : ($open instanceof \Closure ? $open($gap) : $open),
				fn(Gap $gap) => $isLiteral($gap) ? $hug : ($close instanceof \Closure ? $close($gap) : $close),
			];
		}

		return [
			ArrayAccessNode::class => [
				'openBracket' => [$hug, $open],
				'closeBracket' => [$close, null],
			],
		];
	}
}
