<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\IndentationSnapshot;
use DressCode\{Claim, Decision, DecisionKind, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * The signature of a function or a method on a line wider than the line length of the style, that declares promoted
 * properties, that already spans lines (a line break after the opening parenthesis, a parameter or the closing
 * parenthesis beginning a line), or with a parameter whose hooks span several lines has every parameter on a line
 * of its own, each comma on the line of its parameter and the closing parenthesis on the next; where they stand is
 * the matter of `IndentationRule`. The width is that of the code of the line, a comment after it left out, and
 * a signature `file.lineLengthExcept` names is not spread for it. The signature of a closure is left as it is written.
 */
#[RuleInfo(Stage::Formatting, reads: ['file.lineLengthExcept'], analyses: [IndentationSnapshot::class])]
final class MultilineSignatureRule extends GapRule
{
	private const Shape = 'multiline.signature';
	private const Promoted = 'multiline.signaturePromoted';

	private bool $promotedProperty = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				self::Shape,
				new Words(['perLine' => 'every parameter on a line of its own']),
				'The parameters of a signature spread over lines by its author or for its width, or with a parameter whose hooks span lines, each comma on the line of its parameter and the closing parenthesis on the next',
			),
			new Decision(self::Promoted, new Words([
				'always' => 'spread over lines whatever its width',
				'asSignature' => 'spread only when its line is too wide, as any signature',
			]), 'When a signature declaring a promoted property is spread over lines', kind: DecisionKind::Parameter, default: 'always'),
		];
	}


	public function configure(Values $values): void
	{
		$this->promotedProperty = $values->get(self::Promoted)->getWord() === 'always';
	}


	public function getClaims(): array
	{
		$claims = [
			'parameters:item' => [fn(Gap $gap) => $this->claimsToSplit($gap, $gap->value->parent?->parent)[0] ?? null, null],
			'parameters:separator' => [fn(Gap $gap) => $this->claimsToSplit($gap, $gap->token->parent?->parent)[1] ?? null, null],
			'closeParen' => [fn(Gap $gap) => $this->claimsToSplit($gap, $gap->token->parent)[0] ?? null, null],
		];
		return [FunctionNode::class => $claims, MethodNode::class => $claims];
	}


	/**
	 * The claims of a signature whose parameters take lines of their own, the break before a parameter and the
	 * comma hugging one, with the reason; null when they stay on the line. They take lines once the signature
	 * spans lines, and they are made to when one is a promoted property or the line of the signature is too long.
	 * @return ?array{Claim, Claim}
	 */
	private function claimsToSplit(Gap $gap, ?Node $node): ?array
	{
		if ((!$node instanceof FunctionNode && !$node instanceof MethodNode) || $node->parameters->isEmpty()) {
			return null;
		}

		return $gap->once($node, function () use ($node, $gap): ?array {
			$because = $this->findReasonToSplit($node, $gap);
			return $because === null
				? null
				: [
					new Claim(line: Line::Next, because: $because),
					new Claim(Space::None, line: Line::Same, because: $because),
				];
		});
	}


	/**
	 * Why the parameters take lines of their own, null when they stay on the line; the width counts a tab to the
	 * next stop of the style, and waits for the line to be indented.
	 */
	private function findReasonToSplit(FunctionNode|MethodNode $node, Gap $gap): ?string
	{
		$open = $node->openParen;
		if (NodeHelpers::isMultiline($open, $node->parameters->getItems(), $node->closeParen)) {
			return 'the signature spans several lines';
		}

		foreach ($node->parameters->getItems() as $param) {
			if ($param->promoted && $this->promotedProperty) {
				return 'the signature declares a promoted property';
			} elseif ($param->hooks !== null && $param->isMultiLine()) {
				return 'a parameter has hooks spanning several lines';
			}
		}

		if (!$gap->style->lineSplits->has('signature')) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file
		$width = NodeHelpers::measureOverflow($node, $open, $gap);
		return $width !== null && NodeHelpers::isLineInPlace($gap, $open)
			? "the line is $width characters long, more than {$gap->style->maxLineLength}"
			: null;
	}
}
