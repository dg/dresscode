<?php declare(strict_types=1);

// the plugin of a package of upgrading data, whose rule the map its upgrading file sets turns on

use DressCode\{Decision, NodeRule, Plugin, PluginManifest, RuleContext, RuleInfo, Stage};
use DressCode\Domains\{GrammarEntry, Map};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\VariableNode;


#[RuleInfo(Stage::Structure)]
final class UpgradeVariableRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('acmeUpgrade.variables', new Map(new GrammarEntry), 'A variable written instead of another one')];
	}


	public function getVisitedNodes(): array
	{
		return [VariableNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$context->report($node, 'A variable of the upgrade.', fixable: false);
	}
}


final class UpgradePlugin implements Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(rules: [UpgradeVariableRule::class], section: 'acmeUpgrade');
	}
}
