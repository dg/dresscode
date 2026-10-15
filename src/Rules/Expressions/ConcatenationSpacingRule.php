<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Gap, GapRule, RuleInfo, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * A single space, or none, around the concatenation operator, unless it sits at a line break; where it stands at one
 * is the matter of `MultilineBinaryOperatorRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class ConcatenationSpacingRule extends GapRule
{
	private const Spacing = 'spacing.concatenation';

	/** the space around the operator, null where it is kept */
	private ?Claim $claim;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Spacing, new Shapes([
				'spaced' => ['$a . $b', 'a single space around'],
				'compact' => ['$a.$b', 'no space around'],
			]), 'The space around the concatenation operator, unless it sits at a line break'),
		];
	}


	public function configure(Values $values): void
	{
		$this->claim = match ($values->find(self::Spacing)?->getShape()) {
			'spaced' => Claim::singleSpace()->withDecision(self::Spacing),
			'compact' => Claim::noSpace()->withDecision(self::Spacing),
			default => null,
		};
	}


	public function getClaims(): array
	{
		return [BinaryOpNode::class => ['operator' => [
			fn(Gap $gap): ?Claim => $gap->token->text === '.' ? $this->claim : null,
			fn(Gap $gap): ?Claim => $gap->token->text === '.' ? $this->claim : null,
		]]];
	}
}
