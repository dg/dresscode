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
 * at least one on each side, or exactly one, unless the operator sits at a line break. Concatenation has
 * a rule of its own.
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
			'spacing' => Expect::anyOf('atLeastSingle', 'single')->default('atLeastSingle')
				->description('atLeastSingle keeps extra spaces that align assignments or array items, single collapses them to one'),
			'tabAlignment' => Expect::bool(false)->description('Whitespace with a tab that aligns columns stays as well'),
		]);
	}


	public function configure(array $options): void
	{
		$this->claim = match (true) {
			$options['spacing'] === 'single' => $options['tabAlignment'] ? Claim::singleOrTabs() : Claim::single(),
			default => $options['tabAlignment'] ? Claim::atLeastSingleOrTabs() : Claim::atLeastSingle(),
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
