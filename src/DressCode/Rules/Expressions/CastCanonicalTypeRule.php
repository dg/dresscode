<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\CastNode;
use PhpSyntax\Token;


/**
 * The canonical spelling of a cast: the short name in lowercase and no whitespace inside the parentheses,
 * `(int)`, not `(Integer)` or `( int )`. The space between the cast and its operand is cast-spacing.
 */
#[RuleInfo(
	'dresscode/cast-canonical-type',
	Stage::Structure,
	description: 'Writes a cast with the short type name in lowercase',
)]
final class CastCanonicalTypeRule extends Rule
{
	private const ShortNames = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float', 'real' => 'float', 'binary' => 'string'];


	public function getVisitedTypes(): array
	{
		return [CastNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof CastNode) {
			return;
		}

		$cast = $node->cast;
		$name = strtolower(trim($cast->text, "() \t"));
		$canonical = '(' . (self::ShortNames[$name] ?? $name) . ')';
		if ($canonical !== $cast->text && $context->report($cast, "The cast must be written '$canonical'")) {
			$cast->setText($canonical);
		}
	}
}
