<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Token;


/**
 * Which quotes a plain string is written with, that is one that gains nothing from either: no interpolation,
 * no escape sequence that only double quotes know, and no quote of the wanted kind inside. A string that
 * needs what it has is left alone whichever way the decision goes.
 */
#[RuleInfo(
	'dresscode/string-quotes',
	Stage::Structure,
	description: 'Decides which quotes a string that needs neither kind is written with',
	decision: 'quotes',
)]
final class StringQuotesRule extends NodeRule implements ConfigurableRule
{
	private const Single = 'single';
	private const Double = 'double';

	private string $quotes = self::Single;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'quotes' => Expect::anyOf(self::Single, self::Double)->default(self::Single)
				->description('The quotes a plain string is written with'),
		]);
	}


	public function configure(array $options): void
	{
		$this->quotes = $options['quotes'];
	}


	public function getVisitedTypes(): array
	{
		return [StringNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof StringNode) {
			return;
		}

		[$from, $to, $message] = $this->quotes === self::Single
			? ['"', "'", 'A plain string must be written with single quotes']
			: ["'", '"', 'A plain string must be written with double quotes'];
		$content = substr($node->token->text, 1, -1);
		if (
			$node->quote !== $from
			|| str_contains($content, $to)
			|| !self::isPlain($content, $from)
			|| !$context->report($node, $message)
		) {
			return;
		}

		$node->setValue($node->value, $to);
	}


	/**
	 * Whether the content says the same in either kind of quotes: what a double-quoted string escapes is
	 * only the backslash and its own quote, and a single-quoted one has nothing that interpolation would read.
	 */
	private static function isPlain(string $content, string $quote): bool
	{
		return $quote === '"'
			? !str_contains(str_replace(['\\\\', '\"'], '', $content), '\\')
			: !str_contains($content, '$') && !str_contains($content, '{');
	}
}
