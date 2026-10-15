<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use DressCode\Rules\{ListLayout, NodeHelpers};
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * The parameters of a function or a method that already span lines (a line break after the opening parenthesis,
 * a parameter or the closing parenthesis beginning a line) stand in the shapes of `multiline.items.parameter` and
 * `multiline.edges.parameter`, each comma on the line of its parameter, a list its author spread written again only on
 * the axis whose shape is not accepted; a signature on one line is spread in the first shapes when its line is wider
 * than the line length of the style, when `multiline.spreadAlways` names a promoted property it declares, or when
 * a parameter has hooks spanning several lines. Where they stand is the matter of `IndentationRule`. The width is that
 * of the code of the line, a comment after it left out, and a signature `file.lineLengthExcept` names is not spread for
 * it. The signature of a closure is left as it is written.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineSignatureRule extends GapRule
{
	private const SpreadAlways = 'multiline.spreadAlways';

	private bool $promotedProperty = true;
	private ListLayout $layout;


	public static function getDecisions(): array
	{
		return [
			...ListLayout::createDecisions(
				'parameter',
				'parameter',
				'parameters',
				'The parameters of a function or a method spread over lines by their author or for their width, or with a parameter whose hooks span lines, each comma on the line of its parameter',
				'`(`',
				'`)`',
			),
			new Decision(
				self::SpreadAlways,
				new Names(['promotedProperty' => 'the parameters of a signature declaring a promoted property']),
				'What is spread over lines whatever its width',
				kind: DecisionKind::Parameter,
				default: ['promotedProperty'],
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->promotedProperty = in_array('promotedProperty', $values->get(self::SpreadAlways)->getNames(), true);
		$this->layout = ListLayout::fromValues($values, 'parameter');
	}


	public function getClaims(): array
	{
		$claims = [
			'parameters:item' => [
				fn(Gap $gap) => $this->decide($gap, $gap->value->parent?->parent)['items'][$gap->index ?? 0] ?? null,
				null,
			],
			'parameters:separator' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent?->parent)['separator'] ?? null, null],
			'closeParen' => [fn(Gap $gap) => $this->decide($gap, $gap->token->parent)['close'] ?? null, null],
		];
		return [FunctionNode::class => $claims, MethodNode::class => $claims];
	}


	/**
	 * The claims of a signature whose parameters take lines of their own, as `ListLayout::claim()` gives them; null when
	 * they stay on the line. They take lines once the signature spans lines, and they are made to when one is
	 * a promoted property or the line of the signature is too long.
	 * @return ?array{items: list<?Claim>, close: ?Claim, separator: Claim}
	 */
	private function decide(Gap $gap, ?Node $node): ?array
	{
		if ((!$node instanceof FunctionNode && !$node instanceof MethodNode) || $node->parameters->isEmpty()) {
			return null;
		}

		return $gap->once($node, function () use ($node, $gap): ?array {
			$params = $node->parameters->getItems();
			if (NodeHelpers::isMultiline($node->openParen, $params, $node->closeParen)) {
				[$itemShape, $edgeShape] = $this->layout->findShapes($node->openParen, $node->closeParen);
				return $this->layout->claim($params, $node->closeParen, $itemShape, $edgeShape, 'the signature spans several lines');
			}

			$because = $this->layout->items === [] ? null : $this->findReasonToSplit($node, $gap);
			[$itemShape, $edgeShape] = $this->layout->getFirst();
			return $because === null
				? null
				: $this->layout->claim($params, $node->closeParen, $itemShape, $edgeShape, $because, whole: true);
		});
	}


	/**
	 * Why the parameters on one line take lines of their own, null when they stay on it; the width counts a tab to the
	 * next stop of the style, and waits for the line to be indented.
	 */
	private function findReasonToSplit(FunctionNode|MethodNode $node, Gap $gap): ?string
	{
		$open = $node->openParen;
		foreach ($node->parameters->getItems() as $param) {
			if ($param->promoted && $this->promotedProperty) {
				return 'the signature declares a promoted property';
			} elseif ($param->hooks !== null && $param->isMultiLine()) {
				return 'a parameter has hooks spanning several lines';
			}
		}

		if (!$gap->style->lineSplits->has('parameter')) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($node, $open, $gap);
		return $width !== null && NodeHelpers::isLineInPlace($gap, $open)
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}
}
