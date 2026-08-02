<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\FloatNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Token;
use function strlen;


/**
 * Long decimal numbers get the underscore separator every three digits from the configured length on
 * (`1_000_000`, `1.234_567`). A number in another base is left alone: the digits of an address, a mask or
 * a code point are grouped by what they mean, not by threes. A number written with a separator already stays
 * as it is.
 */
#[RuleInfo(
	'dresscode/numeric-literal-separator',
	Stage::Structure,
	description: 'Groups the digits of long numbers with underscores',
)]
final class NumericLiteralSeparatorRule extends Rule implements ConfigurableRule
{
	private int $minDigitsBeforeDecimalPoint = 4;
	private int $minDigitsAfterDecimalPoint = 4;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minDigitsBeforeDecimalPoint' => Expect::int(4)->min(1)->description('An integer part with at least this many digits is grouped, in any base'),
			'minDigitsAfterDecimalPoint' => Expect::int(4)->min(1)->description('A fraction with at least this many digits is grouped'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minDigitsBeforeDecimalPoint = $options['minDigitsBeforeDecimalPoint'];
		$this->minDigitsAfterDecimalPoint = $options['minDigitsAfterDecimalPoint'];
	}


	public function getVisitedTypes(): array
	{
		return [IntegerNode::class, FloatNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof IntegerNode && !$node instanceof FloatNode) {
			return;
		}

		$text = $node->token->text;
		if (str_contains($text, '_')) {
			return;
		}

		if (preg_match('~^0[xXbBoO0-9]~', $text)) {
			return; // another base: the digits of an address or a mask carry their own grouping
		} elseif (preg_match('~^(\d*)(\.\d*)?([eE][+-]?\d+)?$~', $text, $m)) {
			$integer = strlen($m[1]) >= $this->minDigitsBeforeDecimalPoint ? self::group($m[1], 3, fromEnd: true) : $m[1];
			$fraction = isset($m[2]) && strlen($m[2]) - 1 >= $this->minDigitsAfterDecimalPoint ? '.' . self::group(substr($m[2], 1), 3, fromEnd: false) : ($m[2] ?? '');
			$grouped = $integer . $fraction . ($m[3] ?? '');
		} else {
			return;
		}

		if ($grouped !== $text && $context->report($node, 'A long number must group its digits with underscores')) {
			$node->token->setText($grouped);
		}
	}


	/** @param positive-int $size */
	private static function group(string $digits, int $size, bool $fromEnd): string
	{
		if ($fromEnd) {
			return strrev(implode('_', str_split(strrev($digits), $size)));
		}

		return implode('_', str_split($digits, $size));
	}
}
