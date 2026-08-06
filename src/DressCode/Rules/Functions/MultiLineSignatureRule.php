<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Token;
use function count;


/**
 * A signature that would exceed the line length, or that declares promoted properties, has every parameter
 * on a line of its own and the closing parenthesis on the next.
 */
#[RuleInfo(
	'dresscode/multi-line-signature',
	Stage::Formatting,
	description: 'Splits long signatures and constructors with promoted properties into one parameter per line',
)]
final class MultiLineSignatureRule extends Rule implements ConfigurableRule
{
	private int $minLineLength = 121;
	private bool $promotedProperties = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'minLineLength' => Expect::int(121)->min(1)->description('A signature whose line is at least this long is split'),
			'promotedProperties' => Expect::bool(true)->description('A signature declaring a promoted property is split regardless of its length'),
		]);
	}


	public function configure(array $options): void
	{
		$this->minLineLength = $options['minLineLength'];
		$this->promotedProperties = $options['promotedProperties'];
	}


	public function getVisitedTypes(): array
	{
		return [FunctionNode::class, MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ((!$node instanceof FunctionNode && !$node instanceof MethodNode) || count($node->params) === 0) {
			return;
		}

		$promoted = false;
		$multiline = true;
		foreach ($node->params->getItems() as $param) {
			$promoted = $promoted || !$param->modifiers->isEmpty();
			$multiline = $multiline && ($param->getFirstToken()?->startsLine() ?? false);
		}

		$multiline = $multiline && $node->closeParen->startsLine();
		if ($multiline) {
			return;
		}

		$style = $context->getStyle();
		$reason = match (true) {
			$promoted && $this->promotedProperties => 'promoted properties',
			$node->openParen->getLineWidth($style) >= $this->minLineLength => 'a signature longer than ' . ($this->minLineLength - 1) . ' characters',
			default => null,
		};
		if ($reason === null || !$context->report($node->openParen, "Every parameter on its own line: $reason")) {
			return;
		}

		$first = $node->getFirstToken();
		$base = Indentation::normalize($first?->getLineIndentation() ?? '', $style);
		Indentation::breakList($node->params, $node->openParen, $node->closeParen, $base, $style);
	}
}
