<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, RuleInfo, Stage, Style, Values};
use DressCode\Domains\Count;
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\{Indentation, Node};
use PhpSyntax\Nodes\Statement\UseNode;
use function count;


/**
 * The names of a group use spread over lines stand in the shapes of `multiline.items.import` and
 * `multiline.edges.import`, a group its author spread written again only on the axis whose shape is not accepted.
 * A group on one line that would be longer than the line length of the style is spread in the first shapes, the names
 * in `fill` filling the lines evenly up to that length from `multiline.fillMinItems.import` of them; a group of
 * a single name is left alone, there being nothing to spread it into. The comma that ends the names is the matter of
 * `TrailingCommaRule` and where the lines stand of `IndentationRule`.
 */
#[RuleInfo(Stage::Formatting, analyses: [IndentationSnapshot::class])]
final class MultilineImportRule extends GapRule
{
	private const FillMinItems = 'multiline.fillMinItems.import';

	private ListLayout $layout;
	private int $fillMinItems = 2;


	public static function getDecisions(): array
	{
		return [
			...ListLayout::createDecisions(
				'import',
				'name',
				'names',
				'The names of a group use spread over lines, by their author or for the width the import would have on one line',
				'`{`',
				'`}`',
				['Written as `fill`, the names fill the lines evenly from `multiline.fillMinItems.import` of them, fewer standing one on a line'],
			),
			new Decision(
				self::FillMinItems,
				new Count(2, range: false),
				'From how many names a group use written in `fill` fills its lines',
				kind: DecisionKind::Parameter,
				default: 2,
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->layout = ListLayout::fromValues($values, 'import');
		$this->fillMinItems = $values->get(self::FillMinItems)->getCount()[0];
	}


	public function getClaims(): array
	{
		return [UseNode::class => [
			'items:item' => [
				fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent)['items'][$gap->index ?? 0] ?? null,
				null,
			],
			'items:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)['separator'] ?? null, null],
			'closeBrace' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['close'] ?? null, null],
		]];
	}


	/**
	 * What the rule asks of a group spread over lines, as `ListLayout::claim()` gives it: the claim before each name, the
	 * break before the closing brace and the comma hugging one; null for a group that stays as it is.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function decide(Gap $gap, ?Node $node): ?array
	{
		if (!$node instanceof UseNode || $node->openBrace === null || $node->closeBrace === null || count($node->items) < 2) {
			return null;
		}

		return $gap->once($node, function () use ($node, $gap): ?array {
			$names = $node->items->getItems();
			if (NodeHelpers::isMultiline($node->openBrace, $names, $node->closeBrace)) {
				[$itemShape, $edgeShape] = $this->layout->findShapes($node->openBrace, $node->closeBrace);
				return $this->layout->claim($names, $node->closeBrace, $itemShape, $edgeShape, 'the names span several lines');
			}

			$lineLength = $gap->style->maxLineLength;
			if ($this->layout->items === [] || $lineLength === null || !NodeHelpers::isLineInPlace($gap, $node->getFirstToken())) {
				return null; // a line not indented yet is measured a pass later
			}

			$width = self::measureFlat($node, $gap->style);
			if ($width === null || $width <= $lineLength) {
				return null;
			}

			$because = "the import would be $width characters long, more than $lineLength";
			[$itemShape, $edgeShape] = $this->layout->getFirst();
			$packed = $itemShape === ListLayout::Fill && count($names) >= $this->fillMinItems
				? self::packNames($node, $gap->style, $lineLength, $because)
				: null;
			return $this->layout->claim(
				$names,
				$node->closeBrace,
				$packed === null ? ListLayout::PerLine : $itemShape,
				$edgeShape,
				$because,
				$packed,
				whole: true,
			);
		});
	}


	/**
	 * The width the import would have written on one line, its indentation counted in; null where a comment
	 * stands among the names, which no measure of a line can take.
	 */
	private static function measureFlat(UseNode $node, Style $style): ?int
	{
		$first = $node->getFirstToken();
		// the keyword with its space, the type with its space, the prefix, and the backslash, the braces and the semicolon
		$width = Indentation::measure($first->getLineIndentation(), $style->toPhpSyntax())
			+ 4
			+ ($node->kindKeyword === null ? 0 : mb_strlen($node->kindKeyword->text) + 1)
			+ mb_strlen($node->prefix->text ?? '')
			+ 4;
		foreach ($node->items->getItems() as $i => $item) {
			$name = NodeHelpers::measureNode($item);
			if ($name === null) {
				return null;
			}

			$width += $name + ($i > 0 ? 2 : 0); // the comma and the space in front of the name
		}

		return $width;
	}


	/**
	 * The claims before the names of a spread group: a name follows the one before on its line while the line
	 * fits into the length, and begins the next one when it does not.
	 * @return ?list<Claim>
	 */
	private static function packNames(UseNode $node, Style $style, int $lineLength, string $because): ?array
	{
		$indentation = Indentation::measure($node->getFirstToken()->getLineIndentation() . $style->indent, $style->toPhpSyntax());
		return NodeHelpers::packItems($node->items->getItems(), $indentation, $lineLength, $because);
	}
}
