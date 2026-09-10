<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\DeclareItemNode;
use PhpSyntax\Nodes\Expression\AssignmentNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Expression\InstanceofNode;


/**
 * Spaces around binary operators, assignments, `instanceof`, `=>` and the `=` of a default or a constant:
 * one on each side, unless the operator sits at a line break. Whitespace wider than that aligns a column
 * of assignments or of array items, and the option says which of it stays: the one made of spaces, the one
 * made of tabs, either, or none. Concatenation has a rule of its own.
 */
#[RuleInfo(
	'dresscode/binary-operator-spacing',
	Stage::Formatting,
	description: 'Puts spaces around binary operators',
)]
final class BinaryOperatorSpacingRule extends GapRule implements ConfigurableRule
{
	private Claim $claim;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'alignment' => Expect::anyOf('none', 'spaces', 'tabs', 'keep')->default('spaces')
				->description('Which alignment around an operator stays: none collapses it to a single space, spaces and tabs keep the one written with them, keep keeps any'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = match ($options['alignment']) {
			'none' => Claim::single(),
			'spaces' => Claim::atLeastSingle(),
			'tabs' => Claim::singleOrTabs(),
			default => Claim::atLeastSingleOrTabs(),
		};
	}


	public function getClaims(): array
	{
		$operator = fn(Gap $gap): ?Claim => $gap->token->text === '.' ? null : $this->claim; // dresscode/concat-spacing
		$both = [$this->claim, $this->claim];
		// the equals of declare(strict_types=1) is dresscode/declare-spacing's
		$equals = fn(Gap $gap): ?Claim => $gap->token->parent instanceof DeclareItemNode ? null : $this->claim;
		return [
			BinaryOpNode::class => ['operator' => [$operator, $operator]],
			AssignmentNode::class => ['operator' => $both],
			CombinedAssignmentNode::class => ['operator' => $both],
			InstanceofNode::class => ['instanceofKeyword' => $both],
			'*' => ['doubleArrow' => $both, 'equals' => [$equals, $equals]],
		];
	}
}
