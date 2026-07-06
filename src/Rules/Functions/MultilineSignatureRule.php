<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\IndentationPlan;
use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Space, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Indentation, Node};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * The signature of a function or a method on a line wider than the line length of the style, that declares promoted
 * properties, that already spans lines (a line break after the opening parenthesis, a parameter or the closing
 * parenthesis beginning a line), or with a parameter whose hooks span several lines has every parameter on a line
 * of its own, each comma on the line of its parameter and the closing parenthesis on the next; where they stand is
 * the matter of `IndentationRule`. The signature of a closure is left as it is written.
 */
#[RuleInfo(Stage::Formatting, analyses: [IndentationPlan::class])]
final class MultilineSignatureRule extends GapRule
{
	private const OverMaxLength = 'multiline.signatureOverMaxLength';
	private const Promoted = 'multiline.signatureWithPromotedProperties';
	private const Shape = 'multiline.signature';

	private bool $overMaxLength = true;

	private bool $promotedProperty = true;

	/** the parameters of a signature spanning lines take a line each */
	private bool $multiline = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::OverMaxLength, new Words(['split' => 'every parameter on a line of its own']), 'A signature on a line longer than the maximum is spread over lines'),
			new Decision(self::Promoted, new Words([
				'split' => 'spread over lines whatever its length',
				'asSignature' => 'spread only when its line is too long, as any signature',
			]), 'A signature declaring a promoted property', parameter: true, default: 'split'),
			new Decision(self::Shape, new Words(['perLine' => 'every parameter on a line of its own']), 'The parameters of a signature spread over lines, or with a parameter whose hooks span lines, each comma on the line of its parameter and the closing parenthesis on the next'),
		];
	}


	public function configure(Values $values): void
	{
		$this->overMaxLength = !$values->isKept(self::OverMaxLength);
		$this->promotedProperty = $values->get(self::Promoted)->getWord() === 'split';
		$this->multiline = !$values->isKept(self::Shape);
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
			[$because, $decision] = $this->reasonToSplit($node, $gap) ?? [null, null];
			return $because === null
				? null
				: [
					new Claim(line: Line::Next, because: $because, decision: $decision),
					new Claim(Space::None, line: Line::Same, because: $because, decision: $decision),
				];
		});
	}


	/**
	 * Why the parameters take lines of their own and the decision saying so, null when they stay on the line; the
	 * width counts a tab to the next stop of the style, and waits for the line to be indented.
	 * @return ?array{string, string}
	 */
	private function reasonToSplit(FunctionNode|MethodNode $node, Gap $gap): ?array
	{
		$open = $node->openParen;
		if ($this->multiline && NodeHelpers::isMultiline($open, $node->parameters->getItems(), $node->closeParen)) {
			return ['the signature spans several lines', self::Shape];
		}

		foreach ($node->parameters->getItems() as $param) {
			if ($param->promoted && $this->overMaxLength && $this->promotedProperty) {
				return ['the signature declares a promoted property', self::OverMaxLength];
			} elseif ($param->hooks !== null && $this->multiline && $param->isMultiLine()) {
				return ['a parameter has hooks spanning several lines', self::Shape];
			}
		}

		$style = $gap->style;
		if ($style->maxLineLength === null || !$this->overMaxLength) {
			return null;
		}

		// the width first: asking whether the line is in place costs a plan of the whole file after every edit
		$width = Indentation::measureLineWidth($open, $style->toPhpSyntax());
		return $width > $style->maxLineLength && NodeHelpers::isLineInPlace($gap, $open)
			? ["the line is $width characters long, more than $style->maxLineLength", self::OverMaxLength]
			: null;
	}
}
