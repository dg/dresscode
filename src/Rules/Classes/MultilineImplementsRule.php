<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Gap, GapRule, RuleInfo, Stage, Values};
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, SeparatedNodeList};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, InterfaceNode};


/**
 * The interfaces a class or an enum implements, or an interface extends, spread over lines by their author or on
 * a line wider than the line length of the style stand in the shapes of `multiline.items.implements` and
 * `multiline.edges.implements`, each comma on the line of its interface, a list its author spread written again
 * only on the axis whose shape is not accepted; a list of a single interface stays, and so does one on a line where
 * an outer construct is spread first or `file.lineLengthExcept` names `implements`. Where the lines stand is the
 * matter of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineImplementsRule extends GapRule
{
	private ListLayout $layout;


	public static function getDecisions(): array
	{
		return ListLayout::createDecisions(
			'implements',
			'interface',
			'interfaces',
			'The interfaces a class or an enum implements, or an interface extends, spread over lines by their author or for the width of their line',
			'the keyword',
		);
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'implements');
	}


	public function getClaims(): array
	{
		$claims = fn(string $slot) => [
			"$slot:item" => [fn(Gap $gap) => $this->decide($gap, $gap->value->parent)['items'][$gap->index ?? 0] ?? null, null],
			"$slot:separator" => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['separator'] ?? null, null],
		];
		return [
			ClassNode::class => $claims('implements'),
			EnumNode::class => $claims('implements'),
			AnonymousClassNode::class => $claims('implements'),
			InterfaceNode::class => $claims('extends'),
		];
	}


	/**
	 * The claims of interfaces spread over lines, as `ListLayout::claim()` gives them; null when they stay as they are.
	 * Decided once per pass about the list.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function decide(Gap $gap, ?Node $list): ?array
	{
		$keyword = self::findKeyword($list);
		if (!$list instanceof SeparatedNodeList || $keyword === null) {
			return null;
		}

		return $gap->once($list, function () use ($list, $keyword, $gap): ?array {
			$frame = NodeHelpers::findSplitFrame($list);
			if ($frame === null) {
				return null;
			}

			$multiline = $frame[3];
			$because = match (true) {
				$multiline => 'the interfaces span several lines',
				$this->layout->items === [] => null,
				default => NodeHelpers::findWidthReason($list, $gap),
			};
			[$itemShape, $edgeShape] = $multiline ? $this->layout->findShapes($keyword, null) : $this->layout->getFirst();
			return $because === null
				? null
				: $this->layout->claim($list->getItems(), null, $itemShape, $edgeShape, $because, whole: !$multiline);
		});
	}


	/** The keyword the interfaces follow, `implements` or the `extends` of an interface. */
	private static function findKeyword(?Node $list): ?Token
	{
		$parent = $list?->parent;
		return match (true) {
			$parent instanceof InterfaceNode => $parent->extendsKeyword,
			$parent instanceof ClassNode, $parent instanceof EnumNode, $parent instanceof AnonymousClassNode => $parent->implementsKeyword,
			default => null,
		};
	}
}
