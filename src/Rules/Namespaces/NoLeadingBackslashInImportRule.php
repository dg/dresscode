<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Nodes\UseItemNode;
use PhpSyntax\Token;


/**
 * Imported names without the leading backslash: `use Foo\Bar;`, not `use \Foo\Bar;`.
 */
#[RuleInfo(
	'dresscode/no-leading-backslash-in-import',
	Stage::Structure,
	description: 'Removes the leading backslash from imported names',
)]
final class NoLeadingBackslashInImportRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [UseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UseNode) {
			return;
		}

		// a group writes the backslash once, in front of the prefix its items hang on
		$names = $node->prefix === null
			? array_map(fn(UseItemNode $item) => $item->name, $node->items->getItems())
			: [$node->prefix];
		foreach ($names as $name) {
			if (
				$name->kind === NameKind::FullyQualified
				&& $context->report($name, 'An import must not start with a backslash')
			) {
				$name->text = substr($name->token->text, 1);
			}
		}
	}
}
