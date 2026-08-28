<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\Scalar\HeredocNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use function count, strlen;


/**
 * No line longer than the limit, measured visually with a tab in the indentation as the tab width of the
 * style. The lines of a heredoc, a string spanning lines or markup outside PHP tags are content and are not
 * measured; a line inside a multi-line comment is reported on the line the comment starts. The rule runs
 * last, after the rules that break long lines, so it reports what nothing could break.
 */
#[RuleInfo(
	'dresscode/line-length',
	Stage::Cleanup,
	description: 'Reports lines longer than the limit',
)]
final class LineLengthRule extends Rule implements ConfigurableRule
{
	private int $limit = 120;
	private bool $ignoreImports = true;

	/** @var list<string> */
	private array $ignorePatterns = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'limit' => Expect::int(120)->min(1),
			'ignoreImports' => Expect::bool(true)->description('A use import is never reported, it cannot be broken'),
			'ignorePatterns' => Expect::listOf('string')->description('Regular expressions; a line matching one is never reported'),
		]);
	}


	public function configure(array $options): void
	{
		$this->limit = $options['limit'];
		$this->ignoreImports = $options['ignoreImports'];
		$this->ignorePatterns = $options['ignorePatterns'];
	}


	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforeFile(RuleContext $context): void
	{
		$line = self::newLine();
		for ($token = $context->getFile()->getFirstToken(); $token !== null; $token = $token->getNext()) {
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
	private static function newLine(): array
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
		return match ($token->kind) {
			TokenKind::ConstantEncapsedString, TokenKind::InlineHtml, TokenKind::StartHeredoc => true,
			TokenKind::EncapsedAndWhitespace, TokenKind::EndHeredoc => true,
			default => false,
		};
	}


	/** @param array{text: string, content: bool, token: ?Token, owner: ?Token, trivia: ?Trivia} $line */
	private function finishLine(array &$line, RuleContext $context): void
	{
		$text = rtrim($line['text']);
		$width = mb_strlen($text) + (strlen($text) - strlen(ltrim($text, "\t"))) * ($context->getStyle()->tabWidth - 1);
		$owner = $line['owner'];
		if (
			$owner !== null
			&& $width > $this->limit
			&& !$line['content']
			&& !self::isHeredocLine($line['token'])
			&& !($this->ignoreImports && preg_match('~^\s*use\s~i', $text))
			&& !$this->matchesPattern($text)
		) {
			$message = "The line is $width characters long, the limit is $this->limit";
			$line['token'] !== null
				? $context->report($line['token'], $message)
				: $context->report($owner, $message, trivia: $line['trivia']);
		}

		$line = self::newLine();
	}


	private static function isHeredocLine(?Token $token): bool
	{
		$parent = $token?->parent;
		return $parent instanceof HeredocNode || $parent?->findAncestor(HeredocNode::class) !== null;
	}


	private function matchesPattern(string $line): bool
	{
		foreach ($this->ignorePatterns as $pattern) {
			if (preg_match($pattern, $line)) {
				return true;
			}
		}

		return false;
	}
}
