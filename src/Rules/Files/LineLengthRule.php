<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, Domain, LineSplits, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\NodeHelpers;
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, InvalidTagValueNode, PhpDocTagNode};
use PHPStan\PhpDocParser\Lexer\Lexer;
use PhpSyntax\{Indentation, Token, Trivia};
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringNode};
use PhpSyntax\Nodes\Statement\UseNode;
use function count, strlen;


/**
 * No line wider than the line length of the style: a line wider than it is reported, so a length of 120 lets a line
 * of 120 through, and a style without one has nothing reported. The width is what the reader sees, a tab counting
 * to the next stop of the style wherever on the line it stands, and it is the width of the code, a comment after the
 * last token of the line left out, as the rules that break long lines measure it; a line holding nothing but
 * a comment is measured whole. The lines of a heredoc, a string spanning lines or markup outside PHP tags are content
 * and are not measured; a line inside a multi-line comment is measured and reported as any other. Neither is
 * a line `file.lineLengthExcept` leaves: a `use` import with `imports`, a line on which a construct of a kind it names
 * stands, a line that would fit were each of its string literals an empty pair of quotes with `strings`, and a line
 * one of its regular expressions matches. Nor is a line of a doc comment wider than the line length already by
 * the tag on it, its type, signature or name, which the description after them cannot shorten. The rule runs last,
 * after the rules that break long lines, so it reports what nothing could break.
 * @phpstan-type Line array{text: string, code: ?string, blankText: string, blankCode: ?string, content: bool,
 *   token: ?Token, owner: ?Token, trivia: ?Trivia, lineInTrivia: int, docComment: ?Trivia, lineInDocComment: int,
 *   docCommentPrefix: string}
 */
#[RuleInfo(Stage::Finishing, decisions: ['file.lineLength', 'file.lineLengthExcept'], analyses: [PhpDoc::class])]
final class LineLengthRule extends NodeRule
{
	private bool $ignoreImports = false;

	/** a line that fits with its string literals emptied is not reported */
	private bool $ignoreStrings = false;

	/** @var array<string, true>  the kinds of construct whose line is not reported */
	private array $ignoreKinds = [];

	/** @var list<string> */
	private array $ignorePatterns = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'file.longLines',
				Domain::state('forbidden'),
				'A line wider than `file.lineLength` that nothing could split is reported, unless `file.lineLengthExcept` leaves a construct on it or the line itself as it is',
			),
		];
	}


	public function configure(Values $values): void
	{
		foreach ($values->get('file.lineLengthExcept')->getNames() as $name) {
			if ($name === 'imports') {
				$this->ignoreImports = true;
			} elseif ($name === 'strings') {
				$this->ignoreStrings = true;
			} elseif (isset(LineSplits::Kinds[$name])) {
				$this->ignoreKinds[$name] = true;
			} else {
				$this->ignorePatterns[] = $name;
			}
		}
	}


	public function getVisitedNodes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		if ($context->style->maxLineLength === null) {
			return;
		}

		$line = self::createLine();
		$string = null; // the interpolated string being read, emptied with `strings`
		for ($token = $context->file->getFirstToken(); $token !== null; $token = $token->getNext()) {
			$leadingBlank = $string !== null ? '' : null;
			$blank = null;
			if ($string !== null) {
				$blank = $token === $string->closeQuote ? $token->text : '';
				$string = $token === $string->closeQuote ? null : $string;
			} elseif ($this->ignoreStrings && $token->id === Token::ConstantEncapsedString) {
				$blank = "''";
			} elseif ($this->ignoreStrings && $token->parent instanceof InterpolatedStringNode && $token === $token->parent->openQuote) {
				$blank = $token->text;
				$string = $token->parent;
			}

			foreach ($token->leadingTrivia as $trivia) {
				$this->add($line, $trivia->text, $token, $trivia, false, $context, $leadingBlank);
			}

			$this->add($line, $token->text, $token, null, self::isContent($token), $context, $blank);
			foreach ($token->trailingTrivia as $trivia) {
				$this->add($line, $trivia->text, $token, $trivia, false, $context, $string !== null ? '' : null);
			}
		}

		$this->finishLine($line, $context);
	}


	/**
	 * The line being read: its text, the text up to the end of its last token, both again with the string literals
	 * emptied, whether content of a string or markup runs through it, the first token whose own text lands on it,
	 * the token and trivia it starts with, with the line of the trivia it starts on, and the first doc comment on it,
	 * with its line on it and the text of the line before it.
	 * @return Line
	 */
	private static function createLine(): array
	{
		return [
			'text' => '',
			'code' => null,
			'blankText' => '',
			'blankCode' => null,
			'content' => false,
			'token' => null,
			'owner' => null,
			'trivia' => null,
			'lineInTrivia' => 0,
			'docComment' => null,
			'lineInDocComment' => 0,
			'docCommentPrefix' => '',
		];
	}


	/**
	 * @param  Line  $line
	 * @param  ?string  $blank  the text with its string literals emptied, null for the text itself
	 */
	private function add(
		array &$line,
		string $text,
		Token $token,
		?Trivia $trivia,
		bool $content,
		RuleContext $context,
		?string $blank = null,
	): void
	{
		$segments = preg_split('~(\r\n|\r|\n)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
		$spans = count($segments) > 1;
		foreach ($segments as $i => $segment) {
			if ($i % 2 === 1) {
				$this->finishLine($line, $context);
				continue;
			} elseif ($segment === '') {
				continue;
			}

			if ($line['text'] === '' && $line['owner'] === null) {
				$line['owner'] = $token;
				$line['trivia'] = $trivia;
				$line['lineInTrivia'] = intdiv($i, 2);
			}

			if ($trivia === null && $line['token'] === null) {
				$line['token'] = $token;
			}

			if ($trivia?->is(Trivia::DocComment) && $line['docComment'] === null) {
				$line['docComment'] = $trivia;
				$line['lineInDocComment'] = intdiv($i, 2);
				$line['docCommentPrefix'] = $line['text'];
			}

			$line['text'] .= $segment;
			$line['blankText'] .= $spans ? $segment : ($blank ?? $segment);
			if ($trivia === null) {
				$line['code'] = $line['text'];
				$line['blankCode'] = $line['blankText'];
			}

			$line['content'] = $line['content'] || ($content && $spans);
		}
	}


	/** Strings and markup, whose lines are content when they span lines; the body of a heredoc always. */
	private static function isContent(Token $token): bool
	{
		return match ($token->id) {
			Token::ConstantEncapsedString, Token::InlineHtml, Token::StartHeredoc, Token::EncapsedAndWhitespace, Token::EndHeredoc => true,
			default => false,
		};
	}


	/** @param  Line  $line */
	private function finishLine(array &$line, RuleContext $context): void
	{
		$style = $context->style;
		$limit = $style->maxLineLength;
		$phpSyntax = $style->toPhpSyntax();
		$text = rtrim($line['text']);
		$width = Indentation::advance(0, rtrim($line['code'] ?? $text), $phpSyntax);
		$owner = $line['owner'];
		if (
			$owner !== null
			&& $limit !== null
			&& $width > $limit
			&& !$line['content']
			&& !self::isHeredocLine($line['token'])
			&& !($this->ignoreImports && $line['token']?->findAncestor(UseNode::class) !== null)
			&& !$this->matchesPattern($text)
			&& !($this->ignoreStrings && Indentation::advance(0, rtrim($line['blankCode'] ?? $line['blankText']), $phpSyntax) <= $limit)
			&& !$this->holdsIgnoredConstruct($line['token'])
			&& !self::isTagTooWide($line, $context)
		) {
			$message = "The line is $width characters long, more than $limit.";
			$line['token'] !== null
				? $context->report($line['token'], $message, fixable: false)
				: $context->report($owner, $message, trivia: $line['trivia'], lineInTrivia: $line['lineInTrivia'], fixable: false);
		}

		$line = self::createLine();
	}


	private static function isHeredocLine(?Token $token): bool
	{
		$parent = $token?->parent;
		return $parent instanceof HeredocNode || $parent?->findAncestor(HeredocNode::class) !== null;
	}


	private function matchesPattern(string $line): bool
	{
		return array_any($this->ignorePatterns, fn(string $pattern) => preg_match($pattern, $line) === 1);
	}


	/** Whether a construct of a kind `file.lineLengthExcept` names begins on the line of the token and stays on it. */
	private function holdsIgnoredConstruct(?Token $first): bool
	{
		if ($this->ignoreKinds === [] || $first === null) {
			return false;
		}

		$line = $first->getCurrentLine();
		for ($token = $first; $token !== null && $token->getCurrentLine() === $line; $token = $token->getNext()) {
			for ($node = $token->parent; $node !== null; $node = $node->parent) {
				$frame = NodeHelpers::findSplitFrame($node);
				if (
					$frame !== null
					&& !$frame[3]
					&& isset($this->ignoreKinds[$frame[0]])
					&& $frame[1]->getCurrentLine() === $line
				) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Whether the line is wider than the line length already by the tag of the doc comment on it, its type, signature
	 * or name, with what precedes it on the line.
	 * @param  Line  $line
	 */
	private static function isTagTooWide(array $line, RuleContext $context): bool
	{
		$docComment = $line['docComment'];
		if ($docComment === null) {
			return false;
		}

		$end = self::findTagEnds($docComment, $context->getAnalysis(PhpDoc::class))[$line['lineInDocComment']] ?? null;
		if ($end === null) {
			return false;
		}

		$lines = preg_split('~\r\n|\r|\n~', $docComment->text) ?: [];
		$tag = $line['docCommentPrefix'] . substr($lines[$line['lineInDocComment']] ?? '', 0, $end);
		return Indentation::advance(0, $tag, $context->style->toPhpSyntax()) > $context->style->maxLineLength;
	}


	/**
	 * Where the tags of the doc comment end on its lines, their descriptions left out, a tag the parser does not know
	 * being only its name: the line in the comment => the offset on it after which no tag goes on.
	 * @return array<int, int>
	 */
	private static function findTagEnds(Trivia $docComment, PhpDoc $phpDoc): array
	{
		$tokens = $phpDoc->getTokens($docComment);
		$inTag = [];
		foreach ($phpDoc->parse($docComment)->children as $child) {
			if (!$child instanceof PhpDocTagNode) {
				continue;
			}

			$value = $child->value;
			$start = $child->getAttribute('startIndex');
			$end = match (true) {
				$value instanceof GenericTagValueNode, $value instanceof InvalidTagValueNode => $start,
				property_exists($value, 'description') && $value->description !== '' => self::findDescriptionStart(
					$tokens,
					$value->getAttribute('startIndex'),
					$child->getAttribute('endIndex'),
					$value->description,
				) - 1,
				default => $child->getAttribute('endIndex'),
			};
			for ($i = $start; $i <= $end; $i++) {
				$inTag[$i] = true;
			}
		}

		$ends = [];
		$line = $column = 0;
		foreach ($tokens as $i => [$text, $type]) {
			if (isset($inTag[$i]) && $type !== Lexer::TOKEN_HORIZONTAL_WS && $type !== Lexer::TOKEN_PHPDOC_EOL) {
				$ends[$line] = $column + strlen($text);
			}

			$segments = preg_split('~\r\n|\r|\n~', $text) ?: [$text];
			$line += count($segments) - 1;
			$column = count($segments) > 1 ? strlen(end($segments)) : $column + strlen($text);
		}

		return $ends;
	}


	/**
	 * The token the description of a tag begins with, found from the end of the tag, the parser giving the description
	 * as text whose whitespace and leading asterisks differ from the tokens.
	 * @param  list<array{string, int, int}>  $tokens
	 */
	private static function findDescriptionStart(array $tokens, int $valueStart, int $end, string $description): int
	{
		$squeeze = fn(string $s): string => preg_replace('~[\s*]+~', '', $s);
		$target = $squeeze($description);
		$suffix = '';
		$start = $end + 1;
		for ($i = $end; $i >= $valueStart; $i--) {
			$suffix = $squeeze($tokens[$i][0]) . $suffix;
			if ($suffix === $target) {
				$start = $i;
			} elseif (strlen($suffix) > strlen($target)) {
				break;
			}
		}

		return $start;
	}
}
