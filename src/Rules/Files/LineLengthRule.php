<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{ConfigurationException, Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use PhpSyntax\{Indentation, Token, Trivia};
use PhpSyntax\Nodes\Scalar\HeredocNode;
use function count;


/**
 * No line wider than the line length of the style: a line wider than it is reported, so a length of 120 lets a line
 * of 120 through, and a style without one has nothing reported. The width is what the reader sees, a tab counting
 * to the next stop of the style wherever on the line it stands, and `MultilineConditionRule` measures with it
 * too. The lines of a heredoc, a string spanning lines or markup outside PHP tags are content and are not measured;
 * a line inside a multi-line comment is reported on the line the comment starts. The rule runs last, after the rules
 * that break long lines, so it reports what nothing could break.
 */
#[RuleInfo(Stage::Finishing, decisions: ['file.maxLineLength'])]
final class LineLengthRule extends NodeRule
{
	private bool $ignoreImports = true;

	/** @var list<string> */
	private array $ignorePatterns = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('file.longLines', Domain::state('forbidden'), 'A line wider than `file.maxLineLength`, which nothing could split, is reported'),
			new Decision('file.longLinesExcept', new Names, 'The lines never reported: `imports` for a `use` import, which cannot be broken, and regular expressions of others', parameter: true, default: ['imports']),
		];
	}


	/** @throws ConfigurationException for an ignored line that is neither `imports` nor a regular expression */
	public function configure(Values $values): void
	{
		$ignores = $values->get('file.longLinesExcept')->getNames();
		$this->ignoreImports = in_array('imports', $ignores, true);
		$this->ignorePatterns = array_values(array_diff($ignores, ['imports']));
		foreach ($this->ignorePatterns as $pattern) {
			if (@preg_match($pattern, '') === false) { // @ the error is the answer
				throw new ConfigurationException("Key `file.longLinesExcept` takes `imports` and regular expressions, not `$pattern`.");
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
		for ($token = $context->file->getFirstToken(); $token !== null; $token = $token->getNext()) {
			foreach ($token->leadingTrivia as $trivia) {
				$this->add($line, $trivia->text, $token, $trivia, false, $context);
			}

			$this->add($line, $token->text, $token, null, self::isContent($token), $context);
			foreach ($token->trailingTrivia as $trivia) {
				$this->add($line, $trivia->text, $token, $trivia, false, $context);
			}
		}

		$this->finishLine($line, $context);
	}


	/**
	 * The line being read: its text, whether content of a string or markup runs through it, the first token
	 * whose own text lands on it, and the token and trivia it starts with.
	 * @return array{text: string, content: bool, token: ?Token, owner: ?Token, trivia: ?Trivia}
	 */
	private static function createLine(): array
	{
		return ['text' => '', 'content' => false, 'token' => null, 'owner' => null, 'trivia' => null];
	}


	/** @param array{text: string, content: bool, token: ?Token, owner: ?Token, trivia: ?Trivia} $line */
	private function add(
		array &$line,
		string $text,
		Token $token,
		?Trivia $trivia,
		bool $content,
		RuleContext $context,
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
			}

			if ($trivia === null && $line['token'] === null) {
				$line['token'] = $token;
			}

			$line['text'] .= $segment;
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


	/** @param array{text: string, content: bool, token: ?Token, owner: ?Token, trivia: ?Trivia} $line */
	private function finishLine(array &$line, RuleContext $context): void
	{
		$style = $context->style;
		$limit = $style->maxLineLength;
		$text = rtrim($line['text']);
		$width = Indentation::advance(0, $text, $style->toPhpSyntax());
		$owner = $line['owner'];
		if (
			$owner !== null
			&& $limit !== null
			&& $width > $limit
			&& !$line['content']
			&& !self::isHeredocLine($line['token'])
			&& !($this->ignoreImports && preg_match('~^\s*use\s~i', $text))
			&& !$this->matchesPattern($text)
		) {
			$message = "The line is $width characters long, more than $limit.";
			$line['token'] !== null
				? $context->report($line['token'], $message, fixable: false)
				: $context->report($owner, $message, trivia: $line['trivia'], fixable: false);
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
}
