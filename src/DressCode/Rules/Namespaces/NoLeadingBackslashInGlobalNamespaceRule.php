<?php declare(strict_types=1);

namespace DressCode\Rules\Namespaces;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\NameKind;
use PhpSyntax\NameRole;
use PhpSyntax\Node;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * Code in the global namespace references classes, functions and constants without the leading backslash:
 * `new Foo`, `strlen()`, `PHP_EOL`, never `\Foo`. A name whose first segment is shadowed by an import keeps
 * it, because there `\Foo` and `Foo` are two different things. Inside a namespace the backslash says which
 * name is meant and stays; the one of an import belongs to dresscode/no-leading-backslash-in-import.
 */
#[RuleInfo(
	'dresscode/no-leading-backslash-in-global-namespace',
	Stage::Structure,
	description: 'Removes the leading backslash of names referenced in the global namespace',
)]
final class NoLeadingBackslashInGlobalNamespaceRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [NameNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$role = $node instanceof NameNode ? $node->getRole() : null;
		if (
			$role === null
			|| $role === NameRole::Namespace // an import is dresscode/no-leading-backslash-in-import
			|| $node->getKind() !== NameKind::FullyQualified
		) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$parts = $node->getParts();
		// the first segment of a qualified name goes through the class imports whatever the name stands for
		$imports = match (count($parts) > 1 ? NameRole::ClassLike : $role) {
			NameRole::Function => $resolver->getFunctionImports($node),
			NameRole::Constant => $resolver->getConstantImports($node),
			default => $resolver->getClassImports($node),
		};
		if (
			$resolver->getNamespace($node) !== ''
			|| isset($imports[$role === NameRole::Constant && count($parts) === 1 ? $parts[0] : strtolower($parts[0])])
			|| !$context->report($node, 'A name in the global namespace must not start with a backslash')
		) {
			return;
		}

		$old = $node->token;
		$token = new Token(count($parts) === 1 ? TokenKind::Identifier : TokenKind::NameQualified, implode('\\', $parts));
		$token->setLeadingTrivia($old->leadingTrivia);
		$token->setTrailingTrivia($old->trailingTrivia);
		$node->setToken($token);
	}
}
