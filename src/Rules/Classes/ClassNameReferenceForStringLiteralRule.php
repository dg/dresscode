<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Types;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Parser;
use PhpSyntax\SymbolKind;
use PhpSyntax\Token;


/**
 * A string naming a class, interface, trait or enum is its `::class`: `'App\Model\User'` is `User::class`, written
 * the way the scope reaches the name, so that a reader, an IDE and a rename see the reference. The types of the
 * project decide what is a class, which is what a string alone cannot say; a name the project does not declare
 * stays a string, and so does one in another letter case than the declaration, whose `::class` a rule of the casing
 * of names would then turn into another string. A string without a backslash stays too, `'Exception'` being a word
 * as often as a class.
 *
 * `::class` gives the name exactly as the string holds it, so the fix changes nothing, but for a string with a leading
 * backslash, which `::class` drops: that fix is risky.
 */
#[RuleInfo(
	'dresscode/class-name-reference-for-string-literal',
	Stage::Structure,
	description: 'Writes a class name held in a string as ::class',
	group: Group::Cleanup,
	requiresTypes: true,
)]
final class ClassNameReferenceForStringLiteralRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [StringNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof StringNode
			|| !preg_match('~^\\\\?([a-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[a-z_\x80-\xff][\w\x80-\xff]*)*)$~iD', $node->value, $m)
		) {
			return;
		}

		$name = $m[1];
		if (
			!str_contains($node->value, '\\')
			|| $context->getAnalysis(Types::class)->findClassName($name) !== $name
			|| !$context->report($node, "The class name must be written as $name::class, not as a string", risky: str_starts_with($node->value, '\\'))
		) {
			return;
		}

		$short = $context->getAnalysis(NameResolver::class)->getShortName($name, SymbolKind::ClassLike, $node);
		$fetch = (new Parser)->parseExpression("$short::class");
		assert($fetch instanceof ClassConstantFetchNode);
		$node->replaceWith($fetch);
	}
}
