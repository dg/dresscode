<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\DeclareItemNode;
use PhpSyntax\Nodes\Statement\DeclareNode;
use PhpSyntax\Nodes\Statement\InlineHtmlNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Every file of PHP code declares `strict_types=1` as its first statement, on the line after the opening
 * tag or on the line of the tag itself. A missing declaration is added right after the tag and whatever
 * followed the tag stays with the code below; the blank lines around belong to dresscode/header-blank-lines.
 * A file starting with markup is left alone, because PHP refuses the declaration there.
 */
#[RuleInfo(
	'dresscode/strict-types-required',
	Stage::Structure,
	description: 'Requires declare(strict_types=1) as the first statement of a file',
)]
final class StrictTypesRequiredRule extends Rule implements ConfigurableRule
{
	private string $placement = 'ownLine';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'placement' => Expect::anyOf('ownLine', 'openingTagLine')->default('ownLine')
				->description('ownLine puts the declaration on the line after the opening tag, openingTagLine on the line of the tag, which needs afterOpeningTag of dresscode/header-blank-lines set to null'),
		]);
	}


	public function configure(array $options): void
	{
		$this->placement = $options['placement'];
	}


	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforeFile(RuleContext $context): void
	{
		$file = $context->getFile();
		$stmts = $file->stmts->getItems();
		$index = ($stmts[0] ?? null) instanceof InlineHtmlNode && $stmts[0]->isPreamble() ? 1 : 0;
		$first = $stmts[$index] ?? null;
		$token = $first?->getFirstToken();
		$tag = $token?->leadingTrivia[0] ?? null;
		if ($first instanceof InlineHtmlNode || $token === null || $tag?->kind !== TriviaKind::OpenTag) {
			return;
		}

		$item = $first instanceof DeclareNode ? self::findStrictTypes($first) : null;
		if ($item === null) {
			if ($context->report($token, 'Missing declare(strict_types=1)', trivia: $tag)) {
				$this->insert($index, $token, $tag, $context);
			}

			return;
		}

		if (
			trim((string) $item->value) !== '1'
			&& $context->report($item, 'strict_types must be set to 1')
		) {
			$item->value->replaceWith((new Parser)->parseExpression('1'));
		}

		$onOwnLine = false;
		foreach ($token->leadingTrivia as $trivia) {
			$onOwnLine = $onOwnLine || $trivia->isEndOfLine();
		}

		if ($this->placement === 'ownLine' && !$onOwnLine) {
			if ($context->report($token, 'declare(strict_types=1) must be on the line after the opening tag', trivia: $tag)) {
				self::moveToOwnLine($token, $tag, $context->getStyle()->eol);
			}
		} elseif ($this->placement === 'openingTagLine' && $onOwnLine) {
			if ($context->report($token, 'declare(strict_types=1) must be on the line of the opening tag', trivia: $tag)) {
				self::moveToTagLine($first, $tag);
			}
		}
	}


	private static function findStrictTypes(DeclareNode $declare): ?DeclareItemNode
	{
		foreach ($declare->items->getItems() as $item) {
			if (strtolower($item->name->token->text) === 'strict_types') {
				return $item;
			}
		}

		return null;
	}


	/**
	 * Puts a new declaration in front of the first statement; the opening tag moves to it and the rest
	 * of the trivia after the tag stays with the statement.
	 */
	private function insert(int $index, Token $token, Trivia $tag, RuleContext $context): void
	{
		$eol = $context->getStyle()->eol;
		$statement = (new Parser)->parseStatement('declare(strict_types=1);');
		$text = rtrim($tag->text) . ($this->placement === 'ownLine' ? $eol : ' ');
		$statement->getFirstToken()?->setLeadingTrivia([new Trivia(TriviaKind::OpenTag, $text)]);
		$statement->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $eol)]);
		$rest = array_slice($token->leadingTrivia, 1);
		if (($rest[0] ?? null)?->kind === TriviaKind::Whitespace && !$tag->isEndOfLine()) {
			array_shift($rest);
		}

		$token->setLeadingTrivia($rest);
		$context->getFile()->stmts->insert($index, $statement);
	}


	/** The opening tag ends its line and the declaration starts the next one. */
	private static function moveToOwnLine(Token $token, Trivia $tag, string $eol): void
	{
		$token->replaceTrivia($tag, new Trivia(TriviaKind::OpenTag, rtrim($tag->text) . $eol));
		$next = $token->leadingTrivia[1] ?? null;
		if ($next?->kind === TriviaKind::Whitespace) {
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
		$token->setLeadingTrivia([new Trivia(TriviaKind::OpenTag, rtrim($tag->text) . ' ')]);
		$next = $declare->getLastToken()?->getNext();
		if ($next !== null && $rest !== []) {
			$next->setLeadingTrivia([...$rest, ...$next->leadingTrivia]);
		}
	}
}
