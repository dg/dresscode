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
use function count, is_array, is_int;


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

	private ?Token $previous = null;

	/** how many strings the traversal is inside of, where whitespace is the value */
	private int $inString = 0;

	private Style $style;

	/** what the closures decided about the nodes in this pass */
	private Memory $memory;

	private Sink $sink;


	public function __construct(
		private readonly Claims $claims,
	) {
	}


	/** Forgets the claims and the decisions of the previous traversal. */
	public function begin(Style $style, Sink $sink): void
	{
		$this->beforeToken = $this->afterToken = [];
		$this->previous = null;
		$this->inString = 0;
		$this->style = $style;
		$this->memory = new Memory;
		$this->sink = $sink;
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

			$slot = self::slotOf($owner, $node);
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
			$this->inString += self::opensString($token) - self::closesString($token);
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
		$broken = $space === null && !$closeTag && self::breaksLine($previous, $token);
		$line = $closeTag || $inMarkup ? null : self::resolveLine($after['line'] ?? null, $before['line'] ?? null);
		$claim = self::resolveSpace($after['space'] ?? null, $before['space'] ?? null);
		if ($line !== null) {
			$this->sink->line($line, $previous, $token, $claim[1] ?? null, $broken);
			if (($line[1] === Line::Next) !== $broken) {
				return; // the whitespace of the line, or its blank lines, are the business of the next pass
			}
		}

		if ($space !== null) {
			if ($claim !== null) {
				$this->sink->space($claim, $previous, $token, $space);
			}
		} elseif ($broken) {
			$this->resolveBlankLines($after['blankLines'] ?? null, $before['blankLines'] ?? null, $token, blankBelowComment: false);
			$this->resolveBlankLines($after['blankLinesBelowComment'] ?? null, $before['blankLinesBelowComment'] ?? null, $token, blankBelowComment: true);
		}
	}


	/** What `Token::getTrailingSpace()` of the token before answers, the token after it being at hand. */
	private static function findTrailingSpace(Token $previous, Token $token): ?string
	{
		$space = '';
		foreach ($previous->trailingTrivia as $trivia) {
			if ($trivia->id !== Trivia::Whitespace || $trivia->inInterpolation) {
				return null;
			}

			$space .= $trivia->text;
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
				$this->beforeToken[spl_object_id($first)][] = [$before, $subject, $index];
			}
		}

		if ($after !== []) {
			$last = $child instanceof Token ? $child : $child->getLastToken();
			if ($last !== null) {
				$subject = $child instanceof PlainNodeList || $child instanceof SeparatedNodeList ? $child->getItems()[count($child->getItems()) - 1] ?? $child : $child;
				$this->afterToken[spl_object_id($last)][] = [$after, $subject, $index];
			}
		}
	}


	/**
	 * What the claims on the token ask for, component by component: the innermost slot first, a closure given
	 * the gap and abstaining by null, a later claim filling in only what an earlier one left unclaimed.
	 * @param list<array{list<array{Rule, Claim|\Closure(Gap): ?Claim, string}>, Node|Token, ?int}> $levels
	 * @return array{space?: array{Rule, Space, Node|Token, ?string, ?Node}, line?: array{Rule, Line, Node|Token, ?string, ?Node}, blankLines?: array{Rule, int|array{int, ?int}, Node|Token, ?string, ?Node}, blankLinesBelowComment?: array{Rule, int|array{int, ?int}, Node|Token, ?string, ?Node}}  the rule, what it asks for, what the claim was made for, the reason it gives, and the construct its closure decided about
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
					$claim = $claim(new Gap($token, $subject, $index, $this->style, $rule, $this->memory, $this->claims->rules));
					$construct = $this->memory->takeAsked($rule);
				}

				if ($claim === null) {
					continue;
				}

				// two claims of one key deciding one component would take turns by the order of the configuration,
				// which the check at construction cannot see through a closure; a class before '*' is by design
				if ($claim->space !== null) {
					if (!isset($decided['space'])) {
						$decided['space'] = [$rule, $claim->space, $subject, $claim->because, $construct];
						$here['space'] = $key;
					} elseif (($here['space'] ?? null) === $key) {
						Claims::refuseSecond($decided['space'][0], $rule, $side, $key);
					}
				}

				if ($claim->line !== null) {
					if (!isset($decided['line'])) {
						$decided['line'] = [$rule, $claim->line, $subject, $claim->because, $construct];
						$here['line'] = $key;
					} elseif (($here['line'] ?? null) === $key) {
						Claims::refuseSecond($decided['line'][0], $rule, $side, $key);
					}
				}

				if ($claim->blankLines !== null) {
					if (!isset($decided['blankLines'])) {
						$decided['blankLines'] = [$rule, $claim->blankLines, $subject, $claim->because, $construct];
						$here['blankLines'] = $key;
					} elseif (($here['blankLines'] ?? null) === $key) {
						Claims::refuseSecond($decided['blankLines'][0], $rule, $side, $key);
					}
				}

				if ($claim->blankLinesBelowComment !== null) {
					if (!isset($decided['blankLinesBelowComment'])) {
						$decided['blankLinesBelowComment'] = [$rule, $claim->blankLinesBelowComment, $subject, $claim->because, $construct];
						$here['blankLinesBelowComment'] = $key;
					} elseif (($here['blankLinesBelowComment'] ?? null) === $key) {
						Claims::refuseSecond($decided['blankLinesBelowComment'][0], $rule, $side, $key);
					}
				}
			}

			if (isset($decided['space'], $decided['line'], $decided['blankLines'], $decided['blankLinesBelowComment'])) {
				break;
			}
		}

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

		return $opens && (!self::closes($token) || self::blankRun($token, closes: true) !== null);
	}


	/**
	 * The line endings a claim on blank lines governs: those above the first comment of the token's line,
	 * or below the last one when the token closes something and the comment belongs to what is above it.
	 * @return ?array{int, int}  index of the first one in the leading trivia and how many stand there; null
	 *     when a comment keeps the token on its line
	 */
	public static function blankRun(Token $token, bool $closes): ?array
	{
		$leading = $token->leadingTrivia;
		$from = ($leading[0] ?? null)?->id === Trivia::OpenTag ? 1 : 0;
		if ($closes) {
			$comment = null;
			foreach ($leading as $i => $trivia) {
				if ($trivia->isComment()) {
					$comment = $i;
				}
			}

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
	private static function belowCommentRun(Token $token): ?array
	{
		if (self::closes($token)) {
			return null;
		}

		$leading = $token->leadingTrivia;
		$comment = null;
		foreach ($leading as $i => $trivia) {
			if ($trivia->isComment()) {
				$comment = $i;
			}
		}

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
		return $parent !== null && (LayoutData::Roles[$parent::class][self::slotOf($parent, $token)] ?? null) === LayoutRole::Closer;
	}


	/**
	 * The claim that decides the line the token stands on: the next line before the same one, the claim after
	 * the first token on a tie.
	 * @param ?array{Rule, Line, Node|Token, ?string, ?Node} $after
	 * @param ?array{Rule, Line, Node|Token, ?string, ?Node} $before
	 * @return ?array{Rule, Line, Node|Token, ?string, ?Node, string}  the claim with its side
	 */
	private static function resolveLine(?array $after, ?array $before): ?array
	{
		if ($after === null || $before === null) {
			return $after !== null ? [...$after, 'after'] : ($before !== null ? [...$before, 'before'] : null);
		}

		return $before[1] === Line::Next && $after[1] === Line::Same ? [...$before, 'before'] : [...$after, 'after'];
	}


	/**
	 * The claim that decides the whitespace of a line: the stricter of the one after the first token and the
	 * one before the second, the former on a tie.
	 * @param ?array{Rule, Space, Node|Token, ?string, ?Node} $after
	 * @param ?array{Rule, Space, Node|Token, ?string, ?Node} $before
	 * @return ?array{Rule, Space, Node|Token, ?string, ?Node, string}
	 */
	private static function resolveSpace(?array $after, ?array $before): ?array
	{
		return match (true) {
			$after === null && $before === null => null,
			$after === null => [...$before, 'before'],
			$before === null => [...$after, 'after'],
			self::strictness($before[1]) < self::strictness($after[1]) => [...$before, 'before'],
			default => [...$after, 'after'],
		};
	}


	private static function strictness(Space $space): int
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
	 * @param ?array{Rule, int|array{int, ?int}, Node|Token, ?string, ?Node} $after
	 * @param ?array{Rule, int|array{int, ?int}, Node|Token, ?string, ?Node} $before
	 */
	private function resolveBlankLines(?array $after, ?array $before, Token $token, bool $blankBelowComment): void
	{
		if ($after === null && $before === null) {
			return;
		}

		$range = $after === null
			? self::range($before[1])
			: ($before === null ? self::range($after[1]) : self::intersect(self::range($after[1]), self::range($before[1])));
		$run = $blankBelowComment ? self::belowCommentRun($token) : self::blankRun($token, self::closes($token));
		if ($run === null) {
			return;
		}

		[$from, $found] = $run;
		$claim = match (true) {
			$before !== null && !self::within($found, $before[1]) => [...$before, 'before'],
			$after !== null => [...$after, 'after'],
			default => [...$before, 'before'],
		};
		$this->sink->blankLines($claim, $token, $range, $from, $found, $run[2] ?? null);
	}


	/**
	 * @param int|array{int, ?int} $count
	 * @return array{int, ?int}
	 */
	private static function range(int|array $count): array
	{
		return is_int($count) ? [$count, $count] : $count;
	}


	/** @param int|array{int, ?int} $count */
	private static function within(int $found, int|array $count): bool
	{
		[$min, $max] = self::range($count);
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


	private static function slotOf(Node $node, Node|Token $child): string
	{
		return $node->findSlotOf($child) ?? '?';
	}


	/** Whether the token opens a string whose inside is not code: the whitespace there is the value. */
	private static function opensString(Token $token): int
	{
		$parent = $token->parent;
		return (int) (($parent instanceof InterpolatedStringNode && $parent->openQuote === $token)
			|| ($parent instanceof HeredocNode && $parent->openDelimiter === $token)
			|| ($parent instanceof ShellExecNode && $parent->openBacktick === $token));
	}


	private static function closesString(Token $token): int
	{
		$parent = $token->parent;
		return (int) (($parent instanceof InterpolatedStringNode && $parent->closeQuote === $token)
			|| ($parent instanceof HeredocNode && $parent->closeDelimiter === $token)
			|| ($parent instanceof ShellExecNode && $parent->closeBacktick === $token));
	}
}
