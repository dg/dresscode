<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * A single space, or none, around the concatenation operator, unless it sits at a line break; an operator ending
 * a line may be moved to the start of the next one.
 */
#[RuleInfo(
	'dresscode/concat-spacing',
	Stage::Formatting,
	description: 'Puts a single space around the concatenation operator',
)]
final class ConcatSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $claim;
	private Claim $joined;
	private string $operatorPosition;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('single', 'none')->default('single'),
			'operatorPosition' => Expect::anyOf('keep', 'start')->default('keep')
				->description('Where the concatenation operator at a line break stands: keep leaves it where it is, start moves one ending a line to the start of the next unless a comment follows it'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = $options['spacing'] === 'single' ? Claim::single() : Claim::none();
		$this->joined = new Claim($this->claim->space, line: Line::Same);
		$this->operatorPosition = $options['operatorPosition'];
	}


	public function getClaims(): array
	{
		return [BinaryOpNode::class => ['operator' => [
			fn(Gap $gap): ?Claim => $gap->token->text === '.' ? ($this->isMovedToStart($gap) ? Claim::nextLine() : $this->claim) : null,
			fn(Gap $gap): ?Claim => $gap->token->text === '.' ? ($this->isMovedToStart($gap) ? $this->joined : $this->claim) : null,
		]]];
	}


	/**
	 * Whether the operator ends its line and goes to the start of the next one, decided once per pass about the
	 * operation.
	 */
	private function isMovedToStart(Gap $gap): bool
	{
		$token = $gap->token;
		return $this->operatorPosition === 'start'
			&& $token->parent instanceof BinaryOpNode
			&& $gap->once($token->parent, fn() => NodeHelpers::isLineBrokenAfter($token));
	}
}
