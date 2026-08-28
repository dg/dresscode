<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\NodeHelpers;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ConstItemNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\PropertyItemNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Member\TraitUseNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement\ConstNode;
use PhpSyntax\Token;
use function count, in_array;


/**
 * One member per declaration: `const A = 1, B = 2;`, `public $a, $b;` and `use T1, T2;` become one
 * declaration per constant, property or trait, each sharing the modifiers and the type of the original.
 * A trait use with adaptations in braces stays as it is; a `const` outside a class counts as a constant.
 */
#[RuleInfo(
	'dresscode/single-member-per-declaration',
	Stage::Structure,
	description: 'Splits a declaration of several constants, properties or traits into one per member',
)]
final class SingleMemberPerDeclarationRule extends Rule implements ConfigurableRule
{
	/** @var list<string> */
	private array $members = ['constant', 'property', 'trait'];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'members' => Expect::listOf(Expect::anyOf('constant', 'property', 'trait'))->default(['constant', 'property', 'trait'])
				->description('Kinds of members to split; constant also covers a const outside a class'),
		]);
	}


	public function configure(array $options): void
	{
		$this->members = $options['members'];
	}


	public function getVisitedTypes(): array
	{
		return [ClassConstNode::class, ConstNode::class, PropertyNode::class, TraitUseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassConstNode
			&& !$node instanceof ConstNode
			&& !$node instanceof PropertyNode
			&& !$node instanceof TraitUseNode
		) {
			return;
		}

		[$kind, $message] = match (true) {
			$node instanceof PropertyNode => ['property', 'One property per declaration'],
			$node instanceof TraitUseNode => ['trait', 'One trait per use statement'],
			default => ['constant', 'One constant per declaration'],
		};
		$list = $node->parent;
		if (
			($node instanceof TraitUseNode && $node->openBrace !== null)
			|| !in_array($kind, $this->members, strict: true)
			|| !$list instanceof NodeList
			|| count(self::itemsOf($node)) < 2
			|| !$context->report($node, $message)
		) {
			return;
		}

		NodeHelpers::splitItems($node, $list, $node instanceof TraitUseNode ? 'traits' : 'items', $context->getStyle()->eol);
	}


	/** @return SeparatedNodeList<ConstItemNode>|SeparatedNodeList<PropertyItemNode>|SeparatedNodeList<NameNode> */
	private static function itemsOf(ClassConstNode|ConstNode|PropertyNode|TraitUseNode $node): SeparatedNodeList
	{
		return $node instanceof TraitUseNode ? $node->traits : $node->items;
	}
}
