<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Gap, GapRule, RuleInfo, Stage, Values};
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeGroupNode;


/**
 * The attributes of one group `#[A, B]` that already span lines stand in the shapes of `multiline.items.attribute` and
 * `multiline.edges.attribute`, each comma on the line of its attribute, a group its author spread written again only on
 * the axis whose shape is not accepted; a group of several attributes on a single line is spread in the first shapes
 * when its line is wider than the line length of the style, unless an outer construct on the line is spread first or
 * `file.lineLengthExcept` names `attribute`. Where the group stands is the matter of `AttributePositionRule`, where the
 * lines stand of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineAttributeRule extends GapRule
{
	private ListLayout $layout;


	public static function getDecisions(): array
	{
		return ListLayout::createDecisions(
			'attribute',
			'attribute',
			'attributes',
			'The attributes of one group spread over lines by their author or for their width, each comma on the line of its attribute',
			'`#[`',
			'`]`',
		);
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'attribute');
	}


	public function getClaims(): array
	{
		return [AttributeGroupNode::class => [
			'items:item' => [
				fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent)['items'][$gap->index ?? 0] ?? null,
				null,
			],
			'items:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)['separator'] ?? null, null],
			'closeBracket' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['close'] ?? null, null],
		]];
	}


	/**
	 * The claims of a group whose attributes take lines of their own, as `ListLayout::claim()` gives them; null when they
	 * stay on the line.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function decide(Gap $gap, ?Node $node): ?array
	{
		if (!$node instanceof AttributeGroupNode || $node->items->isEmpty()) {
			return null;
		}

		return $this->layout->decide(
			$gap,
			$node,
			$node->openBracket,
			$node->items->getItems(),
			$node->closeBracket,
			'the group of attributes spans several lines',
			fn() => NodeHelpers::findWidthReason($node, $gap),
		);
	}
}
