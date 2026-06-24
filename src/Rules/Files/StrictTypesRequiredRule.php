<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use PhpSyntax\{Builder, Token, Trivia};
use PhpSyntax\Nodes\Statement\{DeclareNode, InlineHtmlNode};


/**
 * Every file of PHP code declares `strict_types=1` as its first statement, on the line after the opening
 * tag or on the line of the tag itself; where other `declare` statements come first, the declaration among
 * them counts and its place is left as it is. A missing declaration is added right after the tag and whatever
 * followed the tag stays with the code below.
 * A file starting with markup is left alone, because PHP refuses the declaration there.
 *
 * A declaration added or set to `1` is risky: an argument of a scalar type the calls of the file coerced becomes
 * a TypeError.
 */
#[RuleInfo(Stage::Structure)]
final class StrictTypesRequiredRule extends NodeRule
{
	private const StrictTypes = 'file.strictTypes';
	private const Placement = 'file.strictTypesPosition';

	private bool $strictTypes = true;

	/** ownLine, openingTagLine or null for keep */
	private ?string $placement = 'ownLine';


	public static function getDecisions(): array
	{
		return [
			new Decision(self::StrictTypes, Domain::state('required'), 'Every file of PHP code declares `strict_types=1` as its first statement, a file starting with markup excepted'),
			new Decision(self::Placement, new Words([
				'openingTagLine' => '`<?php declare(strict_types=1);` on one line',
				'ownLine' => 'on the line below the opening tag',
			]), 'Where the declaration of `strict_types` stands'),
		];
	}


	public function configure(Values $values): void
	{
		$this->strictTypes = !$values->isKept(self::StrictTypes);
		$this->placement = $values->find(self::Placement)?->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		$file = $context->file;
		$stmts = $file->statements->getItems();
		$index = ($stmts[0] ?? null) instanceof InlineHtmlNode && $stmts[0]->isPreamble() ? 1 : 0;
		$first = $stmts[$index] ?? null;
		$token = $first?->getFirstToken();
		$tag = $token?->leadingTrivia[0] ?? null;
		if ($first instanceof InlineHtmlNode || $token === null || !$tag?->is(Trivia::OpenTag)) {
			return;
		}

		$item = $found = null;
		foreach (array_slice($stmts, $index) as $declare) {
			if (!$declare instanceof DeclareNode) {
				break;
			}

			$item = $declare->findDirective('strict_types');
			if ($item !== null) {
				$found = $declare;
				break;
			}
		}

		if ($item === null) {
			if (
				$this->strictTypes
				&& $context->report($token, 'Expected `declare(strict_types=1)` at the start of the file.', decision: self::StrictTypes, trivia: $tag, risk: Risk::BehaviorChanges, because: 'PHP then refuses an argument of a scalar type it used to convert')
			) {
				$this->insert($index, $token, $tag, $context);
			}

			return;
		}

		if (
			$this->strictTypes
			&& $item->value->text !== '1'
			&& $context->report($item, '`strict_types` must be set to `1`.', decision: self::StrictTypes, risk: Risk::BehaviorChanges, because: 'PHP then refuses an argument of a scalar type it used to convert')
		) {
			$item->value->replaceWith((new Builder)->expression('1'));
		}

		if (!$first instanceof DeclareNode || $found !== $first) {
			return;
		}

		$onOwnLine = false;
		foreach ($token->leadingTrivia as $trivia) {
			$onOwnLine = $onOwnLine || $trivia->isLineEnding();
		}

		if ($this->placement === 'ownLine' && !$onOwnLine) {
			if ($context->report($token, '`declare(strict_types=1)` must be on the line after the opening tag.', decision: self::Placement, trivia: $tag)) {
				self::moveToOwnLine($token, $tag, $context->style->lineEnding);
			}
		} elseif ($this->placement === 'openingTagLine' && $onOwnLine) {
			if ($context->report($token, '`declare(strict_types=1)` must be on the line of the opening tag.', decision: self::Placement, trivia: $tag)) {
				self::moveToTagLine($first, $tag);
			}
		}
	}


	/**
	 * Puts a new declaration in front of the first statement; the opening tag moves to it and the rest
	 * of the trivia after the tag stays with the statement.
	 */
	private function insert(int $index, Token $token, Trivia $tag, RuleContext $context): void
	{
		$eol = $context->style->lineEnding;
		$statement = (new Builder)->statement('declare(strict_types=1);');
		$text = rtrim($tag->text) . ($this->placement === 'openingTagLine' ? ' ' : $eol);
		$statement->setEdgeTrivia([new Trivia(Trivia::OpenTag, $text)], [Trivia::fromText($eol)]);
		$rest = array_slice($token->leadingTrivia, 1);
		if (($rest[0] ?? null)?->is(Trivia::Whitespace) && !$tag->isLineEnding()) {
			array_shift($rest);
		}

		$token->setLeadingTrivia($rest);
		$context->file->statements->insert($index, $statement);
	}


	/** The opening tag ends its line and the declaration starts the next one. */
	private static function moveToOwnLine(Token $token, Trivia $tag, string $eol): void
	{
		$token->replaceTrivia($tag, $tag->withText(rtrim($tag->text) . $eol));
		$next = $token->leadingTrivia[1] ?? null;
		if ($next?->is(Trivia::Whitespace)) {
			$token->removeTrivia($next);
		}
	}


	/**
	 * The declaration follows the opening tag on its line; the comments and blank lines that stood between
	 * them move behind the declaration.
	 */
	private static function moveToTagLine(DeclareNode $declare, Trivia $tag): void
	{
		$token = $declare->declareKeyword;
		$rest = array_slice($token->leadingTrivia, 1);
		$token->setLeadingTrivia([new Trivia(Trivia::OpenTag, rtrim($tag->text) . ' ')]);
		$next = $declare->getLastToken()->getNext();
		if ($next !== null && $rest !== []) {
			$next->setLeadingTrivia([...$rest, ...$next->leadingTrivia]);
		}
	}
}
