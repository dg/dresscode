<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\Expression\TernaryNode;


/**
 * Whitespace around `?` and `:` of a ternary, and around `?:` as a whole when the middle operand is left out:
 * at least one space, as the standards word it, or exactly one; an operator at a line break is left alone, unless
 * it ends the line and the option moves it to the start of the next one.
 */
#[RuleInfo(
	'dresscode/ternary-operator-spacing',
	Stage::Formatting,
	description: 'Puts whitespace around the ternary operators',
)]
final class TernaryOperatorSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $claim;
	private Claim $joined;
	private string $operatorPosition;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('atLeastSingle', 'single')->default('atLeastSingle')
				->description('atLeastSingle keeps any number of spaces around the operators, single collapses them to one'),
			'operatorPosition' => Expect::anyOf('keep', 'start')->default('keep')
				->description('Where ? and : of a ternary at a line break stand: keep leaves them where they are, start moves one ending a line to the start of the next unless a comment follows it'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = $options['spacing'] === 'single' ? Claim::single() : Claim::atLeastSingle();
		$this->joined = new Claim($this->claim->space, line: Line::Same);
		$this->operatorPosition = $options['operatorPosition'];
	}


	public function getClaims(): array
	{
		$short = fn(Gap $gap) => $gap->token->parent instanceof TernaryNode && $gap->token->parent->then === null;
		// the break in front of an operator is claimed after the operand before it, because the claim before the
		// operator is dresscode/multi-line-ternary's
		return [
			TernaryNode::class => [
				'condition' => [null, fn(Gap $gap) => $this->claimBreakBefore($gap, 0)],
				'then' => [null, fn(Gap $gap) => $this->claimBreakBefore($gap, 1)],
				'question' => [$this->claim, fn(Gap $gap) => $short($gap) ? Claim::none() : $this->claimAfter($gap, 0)],
				'colon' => [fn(Gap $gap) => $short($gap) ? Claim::none() : $this->claim, fn(Gap $gap) => $this->claimAfter($gap, 1)],
			],
		];
	}


	/** The line break in front of the operator, `?` as 0 and `:` as 1, after the operand before it. */
	private function claimBreakBefore(Gap $gap, int $operator): ?Claim
	{
		$ternary = $gap->value->parent;
		if (!$ternary instanceof TernaryNode || !$this->decideMoves($gap)[$operator]) {
			return null;
		}

		$text = match (true) {
			$operator === 1 => ':',
			$ternary->then === null => '?:',
			default => '?',
		};
		return new Claim(line: Line::Next, because: "the $text operator ends its line");
	}


	/** What follows the operator, `?` as 0 and `:` as 1, joins its line when the operator moves there. */
	private function claimAfter(Gap $gap, int $operator): Claim
	{
		return $this->decideMoves($gap)[$operator] ? $this->joined : $this->claim;
	}


	/**
	 * Whether `?` and whether `:` end their line and go to the start of the next one, `?:` as a whole by its colon,
	 * decided once per pass about the ternary.
	 * @return array{bool, bool}
	 */
	private function decideMoves(Gap $gap): array
	{
		$ternary = $gap->value->parent;
		if ($this->operatorPosition !== 'start' || !$ternary instanceof TernaryNode) {
			return [false, false];
		}

		return $gap->once($ternary, function () use ($ternary): ?array {
			$moves = [
				NodeHelpers::isLineBrokenAfter($ternary->then === null ? $ternary->colon : $ternary->question),
				NodeHelpers::isLineBrokenAfter($ternary->colon),
			];
			// no decision where nothing moves, so that the spaces around the operators stay violations of their own
			return $moves === [false, false] ? null : $moves;
		}) ?? [false, false];
	}
}
