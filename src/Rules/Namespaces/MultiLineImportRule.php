<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Space, Stage, Style};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Indentation, Node};
use PhpSyntax\Nodes\Statement\UseNode;
use function count;


/**
 * A group use whose line would be longer than lineLength is spread over lines: the opening brace ends its line,
 * the names fill the lines up to that length and the closing brace stands on a line of its own. The length is
 * the one the import would have on a single line, so a group already spread keeps its shape while it is short
 * enough, and a group of a single name is left alone, there being nothing to spread it into. The comma that ends
 * the names is the matter of dresscode/trailing-comma and where the lines stand of dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-import',
	Stage::Formatting,
	description: 'Spreads a group use over lines where its line would be too long, its names filling the lines',
)]
final class MultiLineImportRule extends GapRule implements ConfigurableRule
{
	private ?int $lineLength = null;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'lineLength' => Expect::int()->min(1)->nullable()
				->description('A group use whose line would be longer than this is spread over lines; null takes the line length of the style'),
		]);
	}


	public function configure(array $options): void
	{
		$this->lineLength = $options['lineLength'];
	}


	public function getClaims(): array
	{
		return [UseNode::class => [
			'items:item' => [fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent)[0][$gap->index ?? 0] ?? null, null],
			'items:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)[1] ?? null, null],
			'closeBrace' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)[2] ?? null, null],
		]];
	}


	/**
	 * What the rule asks of a group spread over lines: the claim before each name, the comma hugging one and the
	 * break before the closing brace; null for a group that stays as it is.
	 * @return ?array{list<Claim>, Claim, Claim}
	 */
	private function decide(Gap $gap, ?Node $node): ?array
	{
		if (!$node instanceof UseNode || !$node->isGroup() || count($node->items) < 2) {
			return null;
		}

		return $gap->once($node, function () use ($node, $gap): ?array {
			$lineLength = $this->lineLength ?? $gap->style->lineLength;
			$first = $node->getFirstToken();
			if ($lineLength === null || $first === null || !NodeHelpers::isLineInPlace($gap, $first)) {
				return null; // a line not indented yet is measured a pass later
			}

			$width = self::measureFlat($node, $gap->style);
			if ($width === null || $width <= $lineLength) {
				return null;
			}

			$because = "the import would be $width characters long on one line";
			$packed = self::packNow($node, $gap->style, $lineLength, $because);
			return $packed === null
				? null
				: [$packed, new Claim(Space::None, line: Line::Same, because: $because), new Claim(line: Line::Next, because: $because)];
		});
	}


	/**
	 * The width the import would have written on one line, its indentation counted in; null where a comment
	 * stands among the names, which no measure of a line can take.
	 */
	private static function measureFlat(UseNode $node, Style $style): ?int
	{
		$first = $node->getFirstToken();
		if ($first === null) {
			return null;
		}

		// the keyword with its space, the type with its space, the prefix, and the backslash, the braces and the semicolon
		$width = Indentation::width($first->getLineIndentation(), $style->toPhpSyntax())
			+ 4
			+ ($node->type === null ? 0 : mb_strlen($node->type->text) + 1)
			+ mb_strlen(trim((string) $node->prefix))
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
	private static function packNow(UseNode $node, Style $style, int $lineLength, string $because): ?array
	{
		$break = new Claim(line: Line::Next, because: $because);
		$follow = new Claim(line: Line::Same, because: $because);
		$indentation = Indentation::width($node->getFirstToken()?->getLineIndentation() . $style->indent, $style->toPhpSyntax());
		$claims = [];
		$column = 0;
		foreach ($node->items->getItems() as $i => $item) {
			$width = NodeHelpers::measureNode($item);
			if ($width === null) {
				return null;
			}

			$width++; // the comma after it
			if ($i > 0 && $column + 1 + $width <= $lineLength) {
				$claims[] = $follow;
				$column += 1 + $width;
			} else {
				$claims[] = $break;
				$column = $indentation + $width;
			}
		}

		return $claims;
	}
}
