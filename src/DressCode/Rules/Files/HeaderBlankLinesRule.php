<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\Whitespace\BlankLines;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\GroupUseNode;
use PhpSyntax\Nodes\Statement\InlineHtmlNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function is_int;


/**
 * Blank lines between the blocks of a file header: after the opening tag, which then carries no code
 * (a file with markup keeps its tag), after an unbraced namespace declaration, between the groups of
 * imports (classes, functions, constants) and after the imports; imports of one group have no blank line
 * between them. A place set to null is left alone. Comments stay with the code below the blank lines.
 */
#[RuleInfo(
	'dresscode/header-blank-lines',
	Stage::Formatting,
	description: 'Puts a fixed number of blank lines around the blocks of the file header',
)]
final class HeaderBlankLinesRule extends Rule implements ConfigurableRule
{
	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeNamespace = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterOpeningTag = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterNamespace = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $afterImports = 1;

	/** @var int|array{int, ?int}|null */
	private int|array|null $betweenImportGroups = 1;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'beforeNamespace' => BlankLines::schema(1)->description('Before the namespace declaration'),
			'afterOpeningTag' => BlankLines::schema(1)->description('After the line of <?php, which then carries no code; a file with markup outside PHP keeps its tag'),
			'afterNamespace' => BlankLines::schema(1)->description('After an unbraced namespace declaration'),
			'afterImports' => BlankLines::schema(1)->description('After the last import, before the rest of the code'),
			'betweenImportGroups' => BlankLines::schema(1)->description('Between imports of classes, functions and constants; imports of one group never have a blank line between them'),
		]);
	}


	public function configure(array $options): void
	{
		$this->beforeNamespace = $options['beforeNamespace'];
		$this->afterOpeningTag = $options['afterOpeningTag'];
		$this->afterNamespace = $options['afterNamespace'];
		$this->afterImports = $options['afterImports'];
		$this->betweenImportGroups = $options['betweenImportGroups'];
	}


	public function getVisitedTypes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof FileNode) {
			if ($this->afterOpeningTag !== null) {
				$this->fixOpeningTag($node, $this->afterOpeningTag, $context);
			}
		} elseif ($node instanceof NamespaceNode) {
			$this->fixBeforeNamespace($node, $context);
			if ($this->afterNamespace !== null && $node->semicolon !== null) {
				$this->setBlankLines($node->stmts->getItems()[0] ?? null, $this->afterNamespace, 'after the namespace declaration', $context);
			}
		} else {
			return;
		}

		$this->fixImports($node->stmts, $context);
	}


	private function fixBeforeNamespace(NamespaceNode $node, RuleContext $context): void
	{
		$keyword = $node->namespaceKeyword;
		$leading = $keyword->leadingTrivia;
		$start = ($leading[0] ?? null)?->kind === TriviaKind::OpenTag ? 1 : 0;
		$onOwnLine = $start ? $leading[0]->isEndOfLine() : $keyword->startsLine();
		if ($onOwnLine && $this->beforeNamespace !== null) {
			BlankLines::ensure($keyword, $this->beforeNamespace, 'before the namespace declaration', $context);
		}
	}


	/** @param int|array{int, ?int} $count */
	private function fixOpeningTag(FileNode $file, int|array $count, RuleContext $context): void
	{
		$stmts = $file->stmts->getItems();
		$first = $stmts[0] ?? null;
		$token = ($first instanceof InlineHtmlNode ? $stmts[1] ?? null : $first)?->getFirstToken();
		$tag = $token?->leadingTrivia[0] ?? null;
		if ($token === null || $tag?->kind !== TriviaKind::OpenTag || !self::isMonolithic($file)) {
			return;
		}

		if ($tag->isEndOfLine()) {
			BlankLines::ensure($token, $count, 'after the opening tag', $context);
			return;
		}

		if (!$context->report($token, 'The opening tag must be on its own line', trivia: $tag)) {
			return;
		}

		$eol = $context->getStyle()->eol;
		$token->replaceTrivia($tag, new Trivia(TriviaKind::OpenTag, rtrim($tag->text) . $eol));
		if (($token->leadingTrivia[1] ?? null)?->kind === TriviaKind::Whitespace) {
			$token->removeTrivia($token->leadingTrivia[1]);
		}

		$token->setBlankLinesBefore(is_int($count) ? $count : $count[0], $eol);
	}


	/** PHP only: no markup but a hashbang or BOM at the start, and no closing tag. */
	private static function isMonolithic(FileNode $file): bool
	{
		foreach ($file->stmts->getItems() as $i => $stmt) {
			if ($stmt instanceof InlineHtmlNode && ($i > 0 || !$stmt->isPreamble())) {
				return false;
			}
		}

		for ($token = $file->getFirstToken(); $token !== null; $token = $token->getNext()) {
			if ($token->is(TokenKind::CloseTag)) {
				return false;
			}
		}

		return true;
	}


	/** @param  NodeList<StatementNode>  $stmts */
	private function fixImports(NodeList $stmts, RuleContext $context): void
	{
		$previous = null;
		foreach ($stmts->getItems() as $stmt) {
			$isImport = $stmt instanceof UseNode || $stmt instanceof GroupUseNode;
			if ($previous !== null) {
				if (!$stmt instanceof UseNode && !$stmt instanceof GroupUseNode) {
					[$count, $place] = [$this->afterImports, 'after the imports'];
				} elseif (self::getImportGroup($previous) === self::getImportGroup($stmt)) {
					[$count, $place] = [0, 'between imports of one group'];
				} else {
					[$count, $place] = [$this->betweenImportGroups, 'between import groups'];
				}

				if ($count !== null) {
					$this->setBlankLines($stmt, $count, $place, $context);
				}
			}

			$previous = $isImport ? $stmt : null;
		}
	}


	private static function getImportGroup(UseNode|GroupUseNode $import): string
	{
		return strtolower($import->type->text ?? '');
	}


	/** @param int|array{int, ?int} $count */
	private function setBlankLines(?StatementNode $stmt, int|array $count, string $place, RuleContext $context): void
	{
		$token = $stmt?->getFirstToken();
		if ($token !== null && !$token->is(TokenKind::CloseTag) && $token->startsLine()) {
			BlankLines::ensure($token, $count, $place, $context);
		}
	}
}
