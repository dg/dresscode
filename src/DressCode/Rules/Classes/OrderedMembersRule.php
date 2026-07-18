<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\EnumCaseNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Member\TraitUseNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use function count;


/**
 * Members of a class in a configured order of kinds (trait uses, constants and properties by visibility);
 * members of kinds not in the order keep their place after the ordered ones, in the order written.
 */
#[RuleInfo(
	'dresscode/ordered-members',
	Stage::Structure,
	description: 'Orders class members by kind and visibility',
)]
final class OrderedMembersRule extends Rule implements ConfigurableRule
{
	private const Kinds = [
		'use_trait', 'case', 'constant', 'constant_public', 'constant_protected', 'constant_private',
		'property', 'property_public', 'property_protected', 'property_private',
		'property_public_static', 'property_protected_static', 'property_private_static',
		'method', 'method_public', 'method_protected', 'method_private', 'method_public_static', 'method_protected_static', 'method_private_static',
		'construct', 'destruct', 'magic',
	];

	/** @var list<string> */
	private array $order = [
		'use_trait', 'constant', 'constant_public', 'constant_protected', 'constant_private',
		'property_public', 'property_protected', 'property_private',
	];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'order' => Expect::listOf(Expect::anyOf(...self::Kinds))->default([
				'use_trait', 'constant', 'constant_public', 'constant_protected', 'constant_private',
				'property_public', 'property_protected', 'property_private',
			])->description('Kinds of members in the required order; a member takes the most specific kind listed, unlisted kinds follow in the order written'),
		]);
	}


	public function configure(array $options): void
	{
		$this->order = $options['order'];
	}


	public function getVisitedTypes(): array
	{
		return [ClassLikeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassNode
			&& !$node instanceof InterfaceNode
			&& !$node instanceof TraitNode
			&& !$node instanceof EnumNode
			&& !$node instanceof AnonymousClassNode
		) {
			return;
		}

		$members = $node->members->getItems();
		$ranked = [];
		foreach ($members as $i => $member) {
			$ranked[] = [$this->rank($member), $i, $member];
		}

		$sorted = $ranked;
		usort($sorted, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
		$first = null;
		foreach ($sorted as $i => [, , $member]) {
			if ($member !== $members[$i]) {
				$first ??= $members[$i];
			}
		}

		if ($first === null || !$context->report($first, 'Class members are not in the configured order')) {
			return;
		}

		foreach ($members as $member) {
			$node->members->removeItem($member);
		}

		foreach ($sorted as [, , $member]) {
			$node->members->append($member);
		}
	}


	private function rank(Node $member): int
	{
		foreach (self::kindsOf($member) as $kind) {
			$position = array_search($kind, $this->order, strict: true);
			if ($position !== false) {
				return $position;
			}
		}

		return count($this->order);
	}


	/**
	 * Kinds of a member from the most specific to the most general.
	 * @return list<string>
	 */
	private static function kindsOf(Node $member): array
	{
		if ($member instanceof TraitUseNode) {
			return ['use_trait'];
		} elseif ($member instanceof EnumCaseNode) {
			return ['case'];
		}

		[$base, $modifiers] = match (true) {
			$member instanceof ClassConstNode => ['constant', $member->modifiers],
			$member instanceof PropertyNode => ['property', $member->modifiers],
			$member instanceof MethodNode => ['method', $member->modifiers],
			default => [null, null],
		};
		if ($base === null || $modifiers === null) {
			return [];
		}

		$visibility = match (true) {
			$modifiers->has(TokenKind::Private) => 'private',
			$modifiers->has(TokenKind::Protected) => 'protected',
			default => 'public',
		};
		$kinds = [];
		if ($member instanceof MethodNode) {
			$name = strtolower($member->name->token->text);
			$kinds[] = match ($name) {
				'__construct' => 'construct',
				'__destruct' => 'destruct',
				default => str_starts_with($name, '__') ? 'magic' : 'method',
			};
		}

		if ($modifiers->has(TokenKind::Static)) {
			$kinds[] = "{$base}_{$visibility}_static";
		}

		$kinds[] = "{$base}_$visibility";
		$kinds[] = $base;
		return $kinds;
	}
}
