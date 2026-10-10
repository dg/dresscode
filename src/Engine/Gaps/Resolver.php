<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine\Gaps;

use DressCode\{Claim, Gap, Line, Rule, Space, Style};
use PhpSyntax\{LayoutData, LayoutRole, Node, Token, Trivia};
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\{FileNode, ModifiersNode, PlainNodeList, SeparatedNodeList};
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringNode};
use PhpSyntax\Nodes\Statement\InlineHtmlNode;
use function count, is_array;


/**
 * Applies the claims of the gap rules along the traversal of the formatting stage: entering a node hands them to
 * the tokens at the edges of its slot values, reaching a token decides the gap before it component by component
 * and hands the decision to a Sink, the fixer of the pass or the survey of the whitespace fuzz.
 * @internal
 */
final class Resolver
{
	/** @var array<int, list<array{list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>, Node|Token, ?int}>>  token id => the claims before it with what they were made for and its index in a list, slot by slot, the innermost last */
	private array $beforeToken = [];

	/** @var array<int, list<array{list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>, Node|Token, ?int}>> */
	private array $afterToken = [];

	/** @var array<int, Token>  the tokens of the ids above, held so that no other token takes one of the ids in the pass */
	private array $held = [];

	private ?Token $previous = null;

	/** how many strings the traversal is inside of, where whitespace is the value */
	private int $inString = 0;

	private Style $style;

	/** what the closures decided about the nodes in this pass */
	private OnceAnswers $answers;

	private Sink $sink;

	/** @var \Closure(class-string, Rule): ?object  the analysis of the file traversed, for the rule asking it */
	private \Closure $findAnalysis;


	public function __construct(
		private readonly Claims $claims,
	) {
	}


	/**
	 * Forgets the claims and the decisions of the previous traversal.
	 * @param  \Closure(class-string, Rule): ?object  $findAnalysis  the analysis of the file, which a closure of a claim of the rule may ask
	 */
	public function beginPass(Style $style, Sink $sink, \Closure $findAnalysis): void
	{
		$this->beforeToken = $this->afterToken = $this->held = [];
		$this->previous = null;
		$this->inString = 0;
		$this->style = $style;
		$this->answers = new OnceAnswers;
		$this->sink = $sink;
		$this->findAnalysis = $findAnalysis;
	}


	/**
	 * Hands the claims on the slots of the node to the tokens at the edges of their values, and those on the
	 * items and separators of a list to each of them; the deeper node comes later, so the claims of a token end
	 * with the innermost.
	 */
	public function enterNode(Node $node): void
	{
		$class = $node::class;
		if ($node instanceof PlainNodeList || $node instanceof SeparatedNodeList) {
			$owner = $node->parent;
			if ($owner === null) {
				return;
			}

			$slot = self::nameSlot($owner, $node);
			$before = $this->claims->getBefore($owner::class, "$slot:item");
			$after = $this->claims->getAfter($owner::class, "$slot:item");
			if ($before !== [] || $after !== []) {
				foreach ($node->getItems() as $i => $item) {
					$this->give($item, $before, $after, $i);
				}
			}

			$separators = $node instanceof SeparatedNodeList ? $node->getSeparators() : [];
			if ($separators !== []) {
				$before = $this->claims->getBefore($owner::class, "$slot:separator");
				$after = $this->claims->getAfter($owner::class, "$slot:separator");
				foreach ($separators as $i => $separator) {
					$this->give($separator, $before, $after, $i);
				}
			}

			return;
		}

		foreach ($this->claims->getClaimedSlots($class) as $slot) {
			$value = $node instanceof ModifiersNode ? $node->getTokens() : $node->$slot;
			if ($value === null) {
				continue;
			}

			$before = $this->claims->getBefore($class, $slot);
			$after = $this->claims->getAfter($class, $slot);
			foreach (is_array($value) ? $value : [$value] as $child) {
				$this->give($child, $before, $after);
			}
		}
	}


	/**
	 * Decides the gap between the token before and this one, now that every node beginning here has been
	 * entered and has handed over its claims: the line break first, then the whitespace of a line or the
	 * blank lines of a break, whichever the gap holds.
	 */
	public function enterToken(Token $token): void
	{
		$previous = $this->previous;
		$this->previous = $token;
		// the gap before the token is inside a string when the tokens before it opened one and did not close it
		$inString = $this->inString;
		$kind = $token->id;
		if (
			$kind === Token::StartHeredoc
			|| $kind === Token::EndHeredoc
			|| $token->text === '"'
			|| $token->text === '`'
		) {
			$this->inString += self::countOpenedStrings($token) - self::countClosedStrings($token);
		}

		$after = $previous === null ? null : $this->afterToken[spl_object_id($previous)] ?? null;
		$before = $this->beforeToken[spl_object_id($token)] ?? null;
		if (
			($after === null && $before === null)
			|| $inString > 0
			|| $token->is([Token::InlineHtml, Token::HaltCompilerData])
		) {
			return;
		}

		// what stands before an open tag, or next to a close tag, inline HTML or the data after __halt_compiler(),
		// is printed, not layout; what follows the open tag is
		$leading = $token->leadingTrivia;
		$openTag = ($leading[0] ?? null)?->id === Trivia::OpenTag;
		if (
			$previous === null ? !$openTag : (
				!$openTag
				&& $previous->is([Token::CloseTag, Token::InlineHtml, Token::HaltCompilerData])
			)
		) {
			return;
		}

		$after = $previous === null || $after === null ? [] : $this->decide($after, $previous, 'after');
		$before = $before === null ? [] : $this->decide($before, $token, 'before');
		if ($after === [] && $before === []) {
			return;
		}

		$space = $openTag ? null : self::findTrailingSpace($previous, $token);
		// what stands next to a close tag is printed, and so is the line of an open tag inside markup; the tag that
		// opens the code of the file, after nothing or a hashbang, ends the line of the header
		$closeTag = $kind === Token::CloseTag;
		$inMarkup = $openTag
			&& $previous !== null
			&& !($previous->parent instanceof InlineHtmlNode && $previous->getPrevious() === null && $previous->parent->isPreamble());
		$breaksLine = $space === null && !$closeTag && self::breaksLine($previous, $token);
		$line = $closeTag || $inMarkup ? null : self::resolveLine($after['line'] ?? null, $before['line'] ?? null);
		$claim = self::resolveSpace($after['space'] ?? null, $before['space'] ?? null);
		if ($line !== null) {
			$this->sink->acceptLine($line, $previous, $token, $claim?->wanted, $breaksLine);
			if (($line->wanted === Line::Next) !== $breaksLine) {
				return; // the whitespace of the line, or its blank lines, are the business of the next pass
			}
		}

		if ($space !== null) {
			if ($claim !== null) {
				$this->sink->acceptSpace($claim, $previous, $token, $space);
			}
		} elseif ($breaksLine) {
			$this->resolveBlankLines($after['blankLines'] ?? null, $before['blankLines'] ?? null, $token, blankBelowComment: false);
			$this->resolveBlankLines($after['blankLinesBelowComment'] ?? null, $before['blankLinesBelowComment'] ?? null, $token, blankBelowComment: true);
		}
	}


	/**
	 * What `Token::getTrailingSpace()` of the token before answers, the token after it being at hand, with the
	 * whitespace a mutation left in the leading trivia of the token after it, where it does not belong.
	 */
	private static function findTrailingSpace(Token $previous, Token $token): ?string
	{
		$space = '';
		foreach ([$previous->trailingTrivia, $token->leadingTrivia] as $trivias) {
			foreach ($trivias as $trivia) {
				if ($trivia->id !== Trivia::Whitespace || $trivia->inInterpolation) {
					return null;
				}

				$space .= $trivia->text;
			}
		}

		$text = $previous->text;
		$next = $token->text;
		return ($text !== '' && ($text[-1] === "\n" || $text[-1] === "\r"))
			|| ($next !== '' && ($next[0] === "\n" || $next[0] === "\r"))
				? null
				: $space;
	}


	/**
	 * @param list<array{Rule, Claim|\Closure(Gap): ?Claim, string}> $before
	 * @param list<array{Rule, Claim|\Closure(Gap): ?Claim, string}> $after
	 */
	private function give(Node|Token $child, array $before, array $after, ?int $index = null): void
	{
		if ($before !== []) {
			$first = $child instanceof Token ? $child : $child->getFirstToken();
			if ($first !== null) {
				$subject = $child instanceof PlainNodeList || $child instanceof SeparatedNodeList ? $child->getItems()[0] ?? $child : $child;
				$this->beforeToken[$id = spl_object_id($first)][] = [$before, $subject, $index];
				$this->held[$id] = $first;
			}
		}

		if ($after !== []) {
			$last = $child instanceof Token ? $child : $child->getLastToken();
			if ($last !== null) {
				$subject = $child instanceof PlainNodeList || $child instanceof SeparatedNodeList ? $child->getItems()[count($child->getItems()) - 1] ?? $child : $child;
				$this->afterToken[$id = spl_object_id($last)][] = [$after, $subject, $index];
				$this->held[$id] = $last;
			}
		}
	}


	/**
	 * What the claims on the token ask for, component by component: the innermost slot first, a closure given
	 * the gap and abstaining by null, a later claim filling in only what an earlier one left unclaimed.
	 * @param list<array{list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>, Node|Token, ?int}> $levels
	 * @param 'after'|'before' $side
	 * @return array{space?: DecidedClaim<Space>, line?: DecidedClaim<Line>, blankLines?: DecidedClaim<int|array{int, ?int}>, blankLinesBelowComment?: DecidedClaim<int|array{int, ?int}>}
	 */
	private function decide(array $levels, Token $token, string $side): array
	{
		$decided = [];
		for ($i = count($levels) - 1; $i >= 0; $i--) {
			[$claims, $subject, $index] = $levels[$i];
			$here = []; // component => the key of the claim deciding it at this level
			foreach ($claims as [$rule, $claim, $key]) {
				$construct = null;
				if ($claim instanceof \Closure) {
					$claim = $claim(new Gap($token, $subject, $index, $this->style, $rule, $this->answers, $this->findAnalysis));
					$construct = $this->answers->takeAsked($rule);
				}

				if ($claim === null) {
					continue;
				}

				// two claims of one key deciding one component would take turns by the order of the configuration,
				// which the check at construction cannot see through a closure; a class before '*' is by design
				foreach (Claim::Components as $component) {
					if ($claim->$component === null) {
						continue;
					} elseif (!isset($decided[$component])) {
						$decided[$component] = new DecidedClaim($rule, $claim->$component, $subject, $claim, $construct, $side);
						$here[$component] = $key;
					} elseif (($here[$component] ?? null) === $key) {
						Claims::refuseSecond($decided[$component]->rule, $rule, $side, $key);
					}
				}
			}

			if (count($decided) === count(Claim::Components)) {
				break;
			}
		}

		// @phpstan-ignore return.type (each component keeps the type of its property, which `$claim->$component` hides)
		return $decided;
	}


	/**
	 * Whether the token opens a line through trivia: an open tag ending with a line ending, or the trailing
	 * trivia of the token before ending with one; a comment on the line of a token that closes something,
	 * or of the end of the file, keeps that token on the comment's line.
	 */
	private static function breaksLine(?Token $previous, Token $token): bool
	{
		$leading = $token->leadingTrivia;
		$first = $leading[0] ?? null;
		if ($first?->id === Trivia::OpenTag) {
			$opens = $first->isLineEnding();
		} else {
			$trailing = $previous === null ? [] : $previous->trailingTrivia;
			$last = $trailing[count($trailing) - 1] ?? null;
			$opens = $last !== null && $last->id === Trivia::LineEnding && !$last->inInterpolation;
		}

		return $opens && (!self::closes($token) || self::findBlankRun($token, closes: true) !== null);
	}


	/**
	 * The line endings a claim on blank lines governs: those above the first comment of the token's line,
	 * or below the last one when the token closes something and the comment belongs to what is above it.
	 * @return ?array{int, int}  index of the first one in the leading trivia and how many stand there; null
	 *     when a comment keeps the token on its line
	 */
	public static function findBlankRun(Token $token, bool $closes): ?array
	{
		$leading = $token->leadingTrivia;
		$from = ($leading[0] ?? null)?->id === Trivia::OpenTag ? 1 : 0;
		if ($closes) {
			$comment = $token->findLastLeadingCommentIndex();
			if ($comment !== null) {
				$from = $comment + 1;
				while (($leading[$from] ?? null)?->id === Trivia::Whitespace) {
					$from++;
				}

				if (($leading[$from] ?? null)?->id !== Trivia::LineEnding) {
					return null;
				}

				$from++; // the line ending that ends the comment's line
			}
		}

		$count = 0;
		while (($leading[$from + $count] ?? null)?->id === Trivia::LineEnding) {
			$count++;
		}

		return [$from, $count];
	}


	/**
	 * The line endings below the last comment standing on lines of its own above the token, which a claim on
	 * the blank lines below the comment governs; none above a token that closes something, whose comment belongs
	 * to what is above.
	 * @return ?array{int, int, Trivia}  index of the first one in the leading trivia, how many stand there, the comment
	 */
	private static function findBelowCommentRun(Token $token): ?array
	{
		if (self::closes($token)) {
			return null;
		}

		$leading = $token->leadingTrivia;
		$comment = $token->findLastLeadingCommentIndex();
		if ($comment === null || ($leading[$comment + 1] ?? null)?->id !== Trivia::LineEnding) {
			return null;
		}

		$from = $comment + 2;
		$count = 0;
		while (($leading[$from + $count] ?? null)?->id === Trivia::LineEnding) {
			$count++;
		}

		return [$from, $count, $leading[$comment]];
	}


	/** Whether the token closes a construct or the file, so that a comment above it belongs to what is above. */
	public static function closes(Token $token): bool
	{
		$kind = $token->id;
		if ($kind === Token::EndOfFile) {
			return $token->parent instanceof FileNode;
		}

		// only a bracket, an end* keyword or the end of a heredoc closes anything: the slot is looked up for those
		$first = $token->text[0] ?? '';
		if (
			$first !== ')'
			&& $first !== ']'
			&& $first !== '}'
			&& $first !== 'e'
			&& $first !== 'E'
			&& $kind !== Token::EndHeredoc
		) {
			return false;
		}

		$parent = $token->parent;
		return $parent !== null && (LayoutData::Roles[$parent::class][self::nameSlot($parent, $token)] ?? null) === LayoutRole::Closer;
	}


	/**
	 * The claim that decides the line the token stands on: the next line before the same one, the claim after
	 * the first token on a tie.
	 * @param ?DecidedClaim<Line> $after
	 * @param ?DecidedClaim<Line> $before
	 * @return ?DecidedClaim<Line>
	 */
	private static function resolveLine(?DecidedClaim $after, ?DecidedClaim $before): ?DecidedClaim
	{
		if ($after === null || $before === null) {
			return $after ?? $before;
		}

		return $before->wanted === Line::Next && $after->wanted === Line::Same ? $before : $after;
	}


	/**
	 * The claim that decides the whitespace of a line: the stricter of the one after the first token and the
	 * one before the second, the former on a tie.
	 * @param ?DecidedClaim<Space> $after
	 * @param ?DecidedClaim<Space> $before
	 * @return ?DecidedClaim<Space>
	 */
	private static function resolveSpace(?DecidedClaim $after, ?DecidedClaim $before): ?DecidedClaim
	{
		return match (true) {
			$after === null || $before === null => $after ?? $before,
			self::getStrictness($before->wanted) < self::getStrictness($after->wanted) => $before,
			default => $after,
		};
	}


	private static function getStrictness(Space $space): int
	{
		return match ($space) {
			Space::None => 0,
			Space::Single => 1,
			Space::SingleOrTabs => 2,
			Space::AtLeastOne => 3,
			Space::AtLeastOneOrTabs => 4,
		};
	}


	/**
	 * The blank lines of a break, above the comment in the gap or below it: what both claims allow, or the
	 * narrower of two that exclude each other, the one before the second token on a tie; handed over with the
	 * claim the count found violates.
	 * @param ?DecidedClaim<int|array{int, ?int}> $after
	 * @param ?DecidedClaim<int|array{int, ?int}> $before
	 */
	private function resolveBlankLines(?DecidedClaim $after, ?DecidedClaim $before, Token $token, bool $blankBelowComment): void
	{
		if ($after === null && $before === null) {
			return;
		}

		$range = $after === null
			? Claim::toRange($before->wanted)
			: ($before === null ? Claim::toRange($after->wanted) : self::intersect(Claim::toRange($after->wanted), Claim::toRange($before->wanted)));
		$run = $blankBelowComment ? self::findBelowCommentRun($token) : self::findBlankRun($token, self::closes($token));
		if ($run === null) {
			return;
		}

		[$from, $found] = $run;
		$claim = $before !== null && ($after === null || !self::within($found, $before->wanted)) ? $before : $after;
		$this->sink->acceptBlankLines($claim, $token, $range, $from, $found, $run[2] ?? null);
	}


	/** @param int|array{int, ?int} $count */
	private static function within(int $found, int|array $count): bool
	{
		[$min, $max] = Claim::toRange($count);
		return $found >= $min && ($max === null || $found <= $max);
	}


	/**
	 * @param array{int, ?int} $after
	 * @param array{int, ?int} $before
	 * @return array{int, ?int}
	 */
	private static function intersect(array $after, array $before): array
	{
		$min = max($after[0], $before[0]);
		$max = $after[1] === null ? $before[1] : ($before[1] === null ? $after[1] : min($after[1], $before[1]));
		if ($max !== null && $min > $max) { // they exclude each other: the narrower wins, the one before on a tie
			$width = fn(array $range) => $range[1] === null ? PHP_INT_MAX : $range[1] - $range[0];
			return $width($after) < $width($before) ? $after : $before;
		}

		return [$min, $max];
	}


	private static function nameSlot(Node $node, Node|Token $child): string
	{
		return $node->findSlotOf($child) ?? '?';
	}


	/** How many strings whose inside is not code, the whitespace there being the value, the token opens: 1 or 0. */
	private static function countOpenedStrings(Token $token): int
	{
		$parent = $token->parent;
		return (int) (($parent instanceof InterpolatedStringNode && $parent->openQuote === $token)
			|| ($parent instanceof HeredocNode && $parent->openDelimiter === $token)
			|| ($parent instanceof ShellExecNode && $parent->openBacktick === $token));
	}


	private static function countClosedStrings(Token $token): int
	{
		$parent = $token->parent;
		return (int) (($parent instanceof InterpolatedStringNode && $parent->closeQuote === $token)
			|| ($parent instanceof HeredocNode && $parent->closeDelimiter === $token)
			|| ($parent instanceof ShellExecNode && $parent->closeBacktick === $token));
	}
}
