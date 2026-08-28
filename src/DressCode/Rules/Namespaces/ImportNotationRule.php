<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\ConfigurableRule;
use DressCode\NodeHelpers;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\GroupUseNode;
use PhpSyntax\Nodes\Statement\NamespaceNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\StatementNode;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * The shape of the import statements of each kind: `single` gives every class, function or constant a
 * `use` of its own, `combined` puts all imports of the kind of a namespace into one comma-separated `use`.
 * A group use is expanded into that shape, or kept as it is. The order of the imports is the matter of
 * dresscode/ordered-imports.
 */
#[RuleInfo(
	'dresscode/import-notation',
	Stage::Structure,
	description: 'Writes the imports of each kind one per use statement or all in one, and expands group use declarations',
)]
final class ImportNotationRule extends Rule implements ConfigurableRule
{
	private const Kinds = ['classes', 'functions', 'constants'];

	/** @var array<string, ?string>  kind → single, combined or null */
	private array $shapes = ['classes' => 'single', 'functions' => 'single', 'constants' => 'single'];
	private string $groupUse = 'expand';


	public static function getOptionsSchema(): Schema
	{
		$shape = Expect::anyOf('single', 'combined')->default('single')->nullable();
		return Expect::structure([
			'classes' => (clone $shape)->description('single gives every class its own use statement, combined puts all classes of the namespace into one; null leaves them alone'),
			'functions' => clone $shape,
			'constants' => clone $shape,
			'groupUse' => Expect::anyOf('expand', 'keep')->default('expand')
				->description('expand turns a group use of a checked kind into the shape of that kind, keep leaves it as it is'),
		]);
	}


	public function configure(array $options): void
	{
		foreach (self::Kinds as $kind) {
			$this->shapes[$kind] = $options[$kind];
		}

		$this->groupUse = $options['groupUse'];
	}


	public function getVisitedTypes(): array
	{
		return [FileNode::class, NamespaceNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FileNode && !$node instanceof NamespaceNode) {
			return;
		}

		$stmts = $node->stmts;
		foreach ($stmts->getItems() as $stmt) {
			if ($stmt instanceof GroupUseNode && $this->groupUse === 'expand' && $this->isChecked($stmt)) {
				$this->expand($stmt, $stmts, $context);
			}
		}

		$combined = [];
		foreach ($stmts->getItems() as $stmt) {
			if (!$stmt instanceof UseNode) {
				continue;
			}

			$kind = self::kindOf($stmt->type);
			if ($this->shapes[$kind] === 'single' && count($stmt->items) > 1) {
				$this->split($stmt, $stmts, $context);
			} elseif ($this->shapes[$kind] === 'combined') {
				$combined[$kind][] = $stmt;
			}
		}

		foreach ($combined as $kind => $uses) {
			$this->combine($uses, $kind, $context);
		}
	}


	/** Whether a group use imports something of a checked kind. */
	private function isChecked(GroupUseNode $node): bool
	{
		foreach ($node->items->getItems() as $item) {
			if ($this->shapes[self::kindOf($item->type ?? $node->type)] !== null) {
				return true;
			}
		}

		return false;
	}


	private static function kindOf(?Token $type): string
	{
		return match ($type?->kind) {
			TokenKind::Function => 'functions',
			TokenKind::Const => 'constants',
			default => 'classes',
		};
	}


	/**
	 * `use A\{B, C as D};` becomes `use A\B;` and `use A\C as D;`.
	 * @param NodeList<StatementNode> $list
	 */
	private function expand(GroupUseNode $node, NodeList $list, RuleContext $context): void
	{
		if (!$context->report($node, 'A group use declaration must be expanded into single imports')) {
			return;
		}

		$parser = new Parser;
		$statements = [];
		foreach ($node->items->getItems() as $item) {
			$statements[] = $parser->parseStatement(self::describe($node, $item));
		}

		$indentation = $node->getFirstToken()?->getIndentation() ?? '';
		$eol = $context->getStyle()->eol;
		$last = array_pop($statements);
		if ($last === null) {
			return;
		}

		$index = $list->indexOf($node);
		$node->replaceWith($last);
		foreach ($statements as $i => $statement) {
			$head = $last->getFirstToken();
			$leading = $i === 0 && $head ? $head->leadingTrivia : [new Trivia(TriviaKind::Whitespace, $indentation)];
			$statement->getFirstToken()?->setLeadingTrivia($leading);
			$statement->getLastToken()?->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $eol)]);
			$list->insert($index + $i, $statement);
		}

		if (count($statements)) {
			$last->getFirstToken()?->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, $indentation)]);
		}
	}


	private static function describe(GroupUseNode $node, UseItemNode $item): string
	{
		$type = ($item->type ?? $node->type)?->text;
		return 'use '
			. ($type === null ? '' : "$type ")
			. $node->prefix->token->text . '\\' . $item->name->token->text
			. ($item->alias === null ? '' : ' as ' . $item->alias->token->text)
			. ';';
	}


	/**
	 * `use A, B;` becomes `use A;` and `use B;`.
	 * @param NodeList<StatementNode> $list
	 */
	private function split(UseNode $node, NodeList $list, RuleContext $context): void
	{
		if (!$context->report($node, 'One import per use statement')) {
			return;
		}

		NodeHelpers::splitItems($node, $list, 'items', $context->getStyle()->eol);
	}


	/**
	 * The imports of the later statements join the first one; a statement with a comment stays where it is.
	 * @param list<UseNode> $uses
	 */
	private function combine(array $uses, string $kind, RuleContext $context): void
	{
		$uses = array_values(array_filter($uses, fn(UseNode $use) => !self::hasComment($use)));
		$first = $uses[0] ?? null;
		if ($first === null) {
			return;
		}

		foreach (array_slice($uses, 1) as $use) {
			if (!$context->report($use, "All imports of $kind in one use statement")) {
				continue;
			}

			foreach ($use->items->getItems() as $item) {
				$copy = clone $item;
				$copy->getFirstToken()?->setLeadingTrivia([]);
				$copy->getLastToken()?->setTrailingTrivia([]);
				$first->items->append($copy);
			}

			$use->remove();
		}
	}


	/** A comment inside the statement or at the end of its line. */
	private static function hasComment(UseNode $use): bool
	{
		$first = $use->getFirstToken();
		$last = $use->getLastToken();
		return $first === null || $last === null || $first->hasCommentUpTo($last) || $last->hasComment();
	}
}
