<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Rules\BlankLines;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\InlineHtmlNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\TriviaKind;


/**
 * Blank lines between the blocks of a file header: after the opening tag, which then carries no code
 * (a file with markup keeps its tag), after an unbraced namespace declaration, between the groups of
 * imports (classes, functions, constants) and after the imports; imports of one group have no blank line
 * between them. A class, interface, trait, enum or function that follows the header may ask for a count of
 * its own. A place set to null is left alone. Comments stay with the code below the blank lines.
 */
#[RuleInfo(
	'dresscode/header-blank-lines',
	Stage::Formatting,
	description: 'Puts a fixed number of blank lines around the blocks of the file header',
)]
final class HeaderBlankLinesRule extends GapRule implements ConfigurableRule
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

	/** @var int|array{int, ?int}|null */
	private int|array|null $beforeDeclaration = null;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'beforeNamespace' => BlankLines::schema(1)->description('Before the namespace declaration'),
			'afterOpeningTag' => BlankLines::schema(1)->description('After the line of <?php, which then carries no code; a file with markup outside PHP keeps its tag'),
			'afterNamespace' => BlankLines::schema(1)->description('After an unbraced namespace declaration'),
			'afterImports' => BlankLines::schema(1)->description('After the last import, before the rest of the code'),
			'betweenImportGroups' => BlankLines::schema(1)->description('Between imports of classes, functions and constants; imports of one group never have a blank line between them'),
			'beforeDeclaration' => BlankLines::schema(BlankLines::Keep)->description('Before a class, interface, trait, enum or function that follows the namespace or the imports, in place of afterNamespace and afterImports'),
		]);
	}


	public function configure(array $options): void
	{
		$this->beforeNamespace = BlankLines::count($options['beforeNamespace']);
		$this->afterOpeningTag = BlankLines::count($options['afterOpeningTag']);
		$this->afterNamespace = BlankLines::count($options['afterNamespace']);
		$this->afterImports = BlankLines::count($options['afterImports']);
		$this->betweenImportGroups = BlankLines::count($options['betweenImportGroups']);
		$this->beforeDeclaration = BlankLines::count($options['beforeDeclaration']);
	}


	public function getClaims(): array
	{
		$claim = ['statements:item' => [fn(Gap $gap) => $this->claim($gap->value, $gap->index ?? 0), null]];
		return [FileNode::class => $claim, NamespaceNode::class => $claim];
	}


	/**
	 * What the header asks for above the statement: the line of the opening tag above the first, the namespace
	 * above its statements, the imports above what follows them, all of it where the statement follows
	 * several of these.
	 */
	private function claim(Node|Token $stmt, int $index): ?Claim
	{
		$list = $stmt->parent;
		if (!$stmt instanceof StatementNode || !$list instanceof NodeList) {
			return null;
		}

		$owner = $list->parent;
		$previous = $list->getItems()[$index - 1] ?? null;
		$counts = [];
		$line = null;
		if (
			$owner instanceof FileNode
			&& $stmt === self::findFirstCode($owner)
			&& $this->afterOpeningTag !== null
			&& self::isMonolithic($owner)
		) {
			$counts[] = $this->afterOpeningTag;
			$line = Line::Next; // the tag ends its line before the blank lines after it can be counted
		}

		if ($stmt instanceof NamespaceNode && $this->beforeNamespace !== null) {
			$counts[] = $this->beforeNamespace;
		}

		$declared = self::isDeclaration($stmt) ? $this->beforeDeclaration : null;
		if ($owner instanceof NamespaceNode && $owner->semicolon !== null && $index === 0) {
			$counts[] = $declared ?? $this->afterNamespace;
		}

		if ($previous instanceof UseNode) {
			$counts[] = match (true) {
				!$stmt instanceof UseNode => $declared ?? $this->afterImports,
				self::getImportGroup($previous) === self::getImportGroup($stmt) => 0,
				default => $this->betweenImportGroups,
			};
		}

		$count = BlankLines::intersect(array_values(array_filter($counts, fn($count) => $count !== null)));
		return $count === null && $line === null ? null : new Claim(line: $line, blank: $count);
	}


	/** The statement after the opening tag: the first one, or the one after the hashbang or the BOM. */
	private static function findFirstCode(FileNode $file): ?StatementNode
	{
		$stmts = $file->statements->getItems();
		$first = $stmts[0] ?? null;
		$stmt = $first instanceof InlineHtmlNode ? $stmts[1] ?? null : $first;
		return ($stmt?->getFirstToken()?->leadingTrivia[0] ?? null)?->kind === TriviaKind::OpenTag ? $stmt : null;
	}


	/** PHP only: no markup but a hashbang or BOM at the start, and no closing tag. */
	private static function isMonolithic(FileNode $file): bool
	{
		foreach ($file->statements->getItems() as $i => $stmt) {
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


	private static function isDeclaration(StatementNode $stmt): bool
	{
		return $stmt instanceof ClassNode
			|| $stmt instanceof InterfaceNode
			|| $stmt instanceof TraitNode
			|| $stmt instanceof EnumNode
			|| $stmt instanceof FunctionNode;
	}


	private static function getImportGroup(UseNode $import): string
	{
		return strtolower($import->type->text ?? '');
	}
}
