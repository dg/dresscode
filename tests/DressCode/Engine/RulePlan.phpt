<?php declare(strict_types=1);

/**
 * The plan of a configuration dispatches a class to the rules whose visited types it is an instance of and whose
 * callback of that direction is their own, stage by stage in the order of the configuration.
 */

use DressCode\{ConfigurationException, GapRule, NodeRule, Rule, RuleContext, RuleInfo, Stage};
use DressCode\Engine\RulePlan;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\{ExpressionNode, StatementNode};
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


#[RuleInfo('test/expressions', Stage::Structure)]
final class EnterExpressions extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ExpressionNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
	}
}


#[RuleInfo('test/statements', Stage::Structure)]
final class LeaveStatements extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [StatementNode::class];
	}


	public function leave(Node|Token $node, RuleContext $context): void
	{
	}
}


#[RuleInfo('test/tokens', Stage::Formatting)]
final class EnterTokens extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
	}
}


test('a class goes to the rules visiting one of its ancestors, and only for the callback a rule has', function () {
	$plan = new RulePlan([$expressions = new EnterExpressions, $statements = new LeaveStatements, $tokens = new EnterTokens]);
	Assert::same([$expressions, $statements], $plan->stages[Stage::Structure->name]);
	Assert::same(['Structure' => true, 'Formatting' => false, 'Finishing' => false], $plan->leaves);
	Assert::true($plan->claims->isEmpty());

	Assert::same([$expressions], $plan->getRulesVisiting('Structure', VariableNode::class, enter: true));
	Assert::same([], $plan->getRulesVisiting('Structure', VariableNode::class, enter: false));
	Assert::same([], $plan->getRulesVisiting('Structure', ExpressionStatementNode::class, enter: true));
	Assert::same([$statements], $plan->getRulesVisiting('Structure', ExpressionStatementNode::class, enter: false));
	Assert::same([$tokens], $plan->getRulesVisiting('Formatting', Token::class, enter: true));
	Assert::same([], $plan->getRulesVisiting('Structure', Token::class, enter: true));
});


#[RuleInfo('test/bare', Stage::Formatting)]
final class BareRule extends Rule
{
}


#[RuleInfo('test/finishingGaps', Stage::Finishing)]
final class FinishingGaps extends GapRule
{
	public function getClaims(): array
	{
		return [];
	}
}


#[RuleInfo('test/typo', Stage::Structure)]
final class VisitsTypo extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return ['Acme\Shop\NoSuchNode']; // @phpstan-ignore return.type (the typo is the point)
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
	}
}


test('a rule that would never run is refused: one of neither kind, gap claims outside Formatting, a visited type no node is', function () {
	Assert::exception(fn() => new RulePlan([new BareRule]), ConfigurationException::class, 'Rule `test/bare` is neither a NodeRule nor a GapRule.');
	Assert::exception(fn() => new RulePlan([new FinishingGaps]), ConfigurationException::class, 'Rule `test/finishingGaps` is a GapRule, %a% says Finishing.');
	Assert::exception(fn() => new RulePlan([new VisitsTypo]), ConfigurationException::class, 'Rule `test/typo` visits `Acme\Shop\NoSuchNode`, which is no class of a node or a token.');
});
