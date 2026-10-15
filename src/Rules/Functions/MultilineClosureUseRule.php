<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Gap, GapRule, RuleInfo, Stage, Values};
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Node;
use PhpSyntax\Nodes\ClosureUseListNode;


/**
 * The variables of the `use` of a closure that already span lines stand in the shapes of `multiline.items.closureUse`
 * and `multiline.edges.closureUse`, each comma on the line of its variable, a list its author spread written again only
 * on the axis whose shape is not accepted; one on a single line is spread in the first shapes when its line is wider than
 * the line length of the style, unless an outer construct on the line is spread first or `file.lineLengthExcept` names
 * `closureUse`. Where the lines stand is the matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineClosureUseRule extends GapRule
{
	private ListLayout $layout;


	public static function getDecisions(): array
	{
		return ListLayout::createDecisions(
			'closureUse',
			'variable',
			'variables',
			'The variables of the `use` of a closure spread over lines by their author or for their width, each comma on the line of its variable',
			'`(`',
			'`)`',
		);
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'closureUse');
	}


	public function getClaims(): array
	{
		return [ClosureUseListNode::class => [
			'items:item' => [
				fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent)['items'][$gap->index ?? 0] ?? null,
				null,
			],
			'items:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)['separator'] ?? null, null],
			'closeParen' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['close'] ?? null, null],
		]];
	}


	/**
	 * The claims of a `use` whose variables take lines of their own, as `ListLayout::claim()` gives them; null when they
	 * stay on the line.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function decide(Gap $gap, ?Node $node): ?array
	{
		if (!$node instanceof ClosureUseListNode || $node->items->isEmpty()) {
			return null;
		}

		return $this->layout->decide(
			$gap,
			$node,
			$node->openParen,
			$node->items->getItems(),
			$node->closeParen,
			'the use of the closure spans several lines',
			fn() => NodeHelpers::findWidthReason($node, $gap),
		);
	}
}
