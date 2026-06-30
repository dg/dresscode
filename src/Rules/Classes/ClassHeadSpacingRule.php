<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Shapes;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, InterfaceNode, TraitNode};
use PhpSyntax\Token;


/**
 * The head of a class declaration on one line with single spaces between its words:
 * `class Foo extends Bar implements Baz {`, `new class ($a) extends Bar` (or `new class($a)`, as
 * `spacing.anonymousClass` says); the brace may take the next line, and so may the interfaces a class implements or an
 * interface extends, and whatever follows a comment. The modifiers before it are `ConstructSpacingRule`,
 * the backing type of an enum `TypeDeclarationSpacingRule`.
 */
#[RuleInfo(Stage::Formatting)]
final class ClassHeadSpacingRule extends GapRule
{
	private const Head = 'spacing.classHead';
	private const Anonymous = 'spacing.anonymousClass';

	private bool $head = true;

	/** the space between `class` and the arguments of an anonymous class, null where it is kept */
	private ?Space $beforeParenthesis = Space::Single;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Head, new Shapes(['spaced' => ['class Foo extends Bar implements Baz', 'single spaces']]), 'The spaces between the words of the head of a class declaration, which stays on one line unless a comment breaks it, the brace and the implemented interfaces being free to take the next one'),
			new Decision(self::Anonymous, new Shapes([
				'spaced' => ['new class ($a)', 'a single space before the arguments'],
				'compact' => ['new class($a)', 'no space before the arguments'],
			]), 'The space between `class` and the arguments of an anonymous class'),
		];
	}


	public function configure(Values $values): void
	{
		$this->head = !$values->isKept(self::Head);
		$this->beforeParenthesis = match ($values->find(self::Anonymous)?->getShape()) {
			'spaced' => Space::Single,
			'compact' => Space::None,
			default => null,
		};
	}


	public function getClaims(): array
	{
		$joined = new Claim(Space::Single, line: Line::Same, decision: self::Head);
		$single = Claim::singleSpace()->withDecision(self::Head);
		$beforeParenthesis = $this->beforeParenthesis === null ? null : new Claim($this->beforeParenthesis, line: Line::Same, decision: self::Anonymous);
		$anonymous = function (Gap $gap) use ($joined, $single, $beforeParenthesis): ?Claim {
			$claim = match (true) {
				$gap->token->getNext()?->is('(') ?? false => $beforeParenthesis,
				!$this->head => null,
				$gap->token->getNext()?->is('{') ?? false => $single,
				default => $joined,
			};
			return $claim === null ? null : self::releaseLineAtComment($claim, $gap->token, $gap->token->getNext());
		};
		if (!$this->head) {
			return [AnonymousClassNode::class => ['classKeyword' => [null, $anonymous]]];
		}

		$before = fn(Gap $gap) => self::releaseLineAtComment($joined, $gap->token->getPrevious(), $gap->token);
		$after = fn(Gap $gap) => self::releaseLineAtComment($joined, $gap->token, $gap->token->getNext());
		$extends = ['extendsKeyword' => [$before, $after]];
		$implements = ['implementsKeyword' => [$before, $single]];
		$brace = ['openBrace' => [$single, null]];
		return [
			ClassNode::class => ['classKeyword' => [null, $after]] + $extends + $implements + $brace,
			InterfaceNode::class => ['interfaceKeyword' => [null, $after], 'extendsKeyword' => [$before, $single]] + $brace,
			TraitNode::class => ['traitKeyword' => [null, $after]] + $brace,
			EnumNode::class => ['enumKeyword' => [null, $after]] + $implements + $brace,
			AnonymousClassNode::class => ['classKeyword' => [null, $anonymous]] + $extends + $implements + $brace,
		];
	}


	/** The claim without its line where a comment stands in the gap, whose line break is not the rule's to take out. */
	private static function releaseLineAtComment(Claim $claim, ?Token $first, ?Token $second): Claim
	{
		return $claim->line !== null && $first !== null && $second !== null && $first->hasCommentUpTo($second)
			? new Claim($claim->space, decision: $claim->decision)
			: $claim;
	}
}
