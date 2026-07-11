<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\NameKind;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\GroupUseNode;
use PhpSyntax\Nodes\Statement\UseNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * Imported names without the leading backslash: `use Foo\Bar;`, not `use \Foo\Bar;`.
 */
#[RuleInfo(
	'dresscode/no-leading-backslash-in-import',
	Stage::Structure,
	description: 'Removes the leading backslash from imported names',
)]
final class NoLeadingBackslashInImportRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [UseNode::class, GroupUseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$names = match (true) {
			$node instanceof UseNode => array_map(fn($item) => $item->name, $node->items->getItems()),
			$node instanceof GroupUseNode => [$node->prefix],
			default => [],
		};
		foreach ($names as $name) {
			if (
				$name->getKind() === NameKind::FullyQualified
				&& $context->report($name, 'An import must not start with a backslash')
			) {
				$text = substr($name->token->text, 1);
				$token = new Token(str_contains($text, '\\') ? TokenKind::NameQualified : TokenKind::Identifier, $text);
				$token->setLeadingTrivia($name->token->leadingTrivia);
				$token->setTrailingTrivia($name->token->trailingTrivia);
				$name->setToken($token);
			}
		}
	}
}
