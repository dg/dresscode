<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Expressions;

use DressCode\{Claim, Decision, Domain, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\Expression\BinaryOpNode;


/**
 * A single space, or none, around the concatenation operator, unless it sits at a line break; an operator ending
 * a line may be moved to the start of the next one, as `multiline.operatorPosition.concatenation` says.
 */
#[RuleInfo(Stage::Formatting)]
final class ConcatenationSpacingRule extends GapRule
{
	private const Spacing = 'spacing.concatenation';
	private const Position = 'multiline.operatorPosition.concatenation';

	/** the space around the operator, null where it is kept */
	private ?Claim $claim;

	/** the break in front of an operator moved to the start of the next line, null where it stays */
	private ?Claim $moved;

	/** what follows an operator moved to the start of the next line */
	private Claim $afterMoved;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Spacing, new Shapes([
				'spaced' => ['$a . $b', 'a single space around'],
				'compact' => ['$a.$b', 'no space around'],
			]), 'The space around the concatenation operator, unless it sits at a line break'),
			new Decision(self::Position, Domain::lineStart(), 'Where the concatenation operator stands at a line break'),
		];
	}


	public function configure(Values $values): void
	{
		$this->claim = match ($values->find(self::Spacing)?->getShape()) {
			'spaced' => Claim::singleSpace()->withDecision(self::Spacing),
			'compact' => Claim::noSpace()->withDecision(self::Spacing),
			default => null,
		};
		$this->moved = $values->isKept(self::Position) ? null : Claim::nextLine()->withDecision(self::Position);
		$this->afterMoved = new Claim($this->claim?->space, line: Line::Same, decision: self::Position);
	}


	public function getClaims(): array
	{
		return [BinaryOpNode::class => ['operator' => [
			fn(Gap $gap): ?Claim => $gap->token->text === '.' ? ($this->isMovedToStart($gap) ? $this->moved : $this->claim) : null,
			fn(Gap $gap): ?Claim => $gap->token->text === '.' ? ($this->isMovedToStart($gap) ? $this->afterMoved : $this->claim) : null,
		]]];
	}


	/**
	 * Whether the operator ends its line and goes to the start of the next one, decided once per pass about the
	 * operation.
	 */
	private function isMovedToStart(Gap $gap): bool
	{
		$token = $gap->token;
		return $this->moved !== null
			&& $token->parent instanceof BinaryOpNode
			&& $gap->once($token->parent, $token->isFollowedByLineEnding(...));
	}
}
