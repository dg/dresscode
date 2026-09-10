<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Style;


/**
 * A signature that would exceed the line length, that declares promoted properties, or whose parameters
 * already begin on a line of their own has every parameter on a line of its own, each comma on the line of
 * its parameter and the closing parenthesis on the next; where they stand is the matter of dresscode/indentation.
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

	private int $minLineLength = 121;
	private string $promotedProperties = self::OwnLines;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minLineLength' => Expect::int(121)->min(1)->description('A signature whose line is at least this long is split'),
			'promotedProperties' => Expect::anyOf(self::OwnLines, self::Keep)->default(self::OwnLines)
				->description('ownLines splits a signature declaring a promoted property whatever its length, keep leaves the length to decide'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minLineLength = $options['minLineLength'];
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
			$because = $this->reasonToSplit($node, $gap->style);
			return $because === null
				? null
				: [new Claim(line: Line::Next, because: $because), new Claim(Space::None, line: Line::Same, because: $because)];
		});
	}


	/** The width of the line depends on the tab width of the style. */
	private function reasonToSplit(FunctionNode|MethodNode $node, Style $style): ?string
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

		$width = $open->getLineWidth($style);
		return $width >= $this->minLineLength ? "the line is $width characters long" : null;
	}
}
