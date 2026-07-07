<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Claim, ConfigurableRule, Gap, GapRule, Line, RuleInfo, Space, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;


/**
 * A signature on a line wider than the line length of the style, that declares promoted properties, or whose
 * parameters already begin on a line of their own has every parameter on a line of its own, each comma on the
 * line of its parameter and the closing parenthesis on the next; where they stand is the matter of
 * dresscode/indentation.
 */
#[RuleInfo(
	'dresscode/multi-line-signature',
	Stage::Formatting,
	description: 'Splits long signatures and constructors with promoted properties into one parameter per line',
)]
final class MultiLineSignatureRule extends GapRule implements ConfigurableRule
{
	private const OwnLines = 'ownLines';
	private const Keep = 'keep';

	private string $promotedProperties = self::OwnLines;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'promotedProperties' => Expect::anyOf(self::OwnLines, self::Keep)->default(self::OwnLines)
				->description('ownLines splits a signature declaring a promoted property whatever its length, keep leaves the length to decide'),
		]);
	}


	public function configure(array $options): void
	{
		$this->promotedProperties = $options['promotedProperties'];
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
	 * comma hugging one, with the reason; null when they stay on the line. They take lines once the first of
	 * them or the closing parenthesis begins a line, and they are made to when one is a promoted property or the
	 * line of the signature is too long.
	 * @return ?array{Claim, Claim}
	 */
	private function claimsToSplit(Gap $gap, ?Node $node): ?array
	{
		if ((!$node instanceof FunctionNode && !$node instanceof MethodNode) || $node->parameters->isEmpty()) {
			return null;
		}

		return $gap->once($node, function () use ($node, $gap): ?array {
			$because = $this->reasonToSplit($node, $gap);
			return $because === null
				? null
				: [new Claim(line: Line::Next, because: $because), new Claim(Space::None, line: Line::Same, because: $because)];
		});
	}


	/**
	 * Why the parameters take lines of their own, null when they stay on the line; the width counts a tab to the
	 * next stop of the style, and waits for the line to be indented.
	 */
	private function reasonToSplit(FunctionNode|MethodNode $node, Gap $gap): ?string
	{
		$open = $node->openParen;
		if (
			(
				$open->getTrailingSpace() === null
				&& !$open->hasComment()
			)
			|| $node->parameters->getItems()[0]->getFirstToken()?->startsLine()
			|| $node->closeParen->startsLine()
		) {
			return 'the signature spans several lines';
		}

		if ($this->promotedProperties === self::OwnLines) {
			foreach ($node->parameters->getItems() as $param) {
				if ($param->isPromoted()) {
					return 'the signature declares a promoted property';
				}
			}
		}

		$style = $gap->style;
		if ($style->lineLength === null || !NodeHelpers::isLineInPlace($gap, $open)) {
			return null;
		}

		$width = $open->getLineWidth($style->toPhpSyntax());
		return $width > $style->lineLength ? "the line is $width characters long" : null;
	}
}
