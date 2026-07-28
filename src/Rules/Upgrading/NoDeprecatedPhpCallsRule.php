<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{Config, Decision, DecisionKind, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{Expression, NameNode};


/**
 * What the upgrading data of PHP say of a call PHP retired (`PhpUpgradingData`): a deprecated function is reported whatever the
 * target, since the code may run on the version that deprecated it.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoDeprecatedPhpCallsRule extends NodeRule
{
	/** @var array<string, true>  the names of the entries withdrawn, in lower case */
	private array $except = [];

	private readonly PhpUpgradingData $data;


	/** The upgrading data of PHP DressCode ships, or others a test gives. */
	public function __construct(?PhpUpgradingData $data = null)
	{
		$this->data = $data ?? PhpUpgradingData::fromFile();
	}


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'upgrading.php.deprecatedCall',
				Domain::state(),
				'A call of a function PHP deprecated, reported as the upgrading data of PHP say',
			),
			new Decision(
				'upgrading.php.deprecatedCallExcept',
				new Names,
				'The entries of the upgrading data of PHP withdrawn, by the name of the function in any letter case',
				kind: DecisionKind::Parameter,
				default: [],
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->except = array_fill_keys(array_map(strtolower(...), $values->get('upgrading.php.deprecatedCallExcept')->getNames()), true);
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Expression\FunctionCallNode) {
			return;
		}

		$entries = $this->data->getEntries();
		$function = GlobalCalls::findFunction($node, $entries, $context);
		if ($function === null || isset($this->except[$function])) {
			return;
		}

		$deprecation = $entries[$function][0];
		if ($node->name instanceof NameNode) {
			// the oldest version DressCode targets stands for every version before it, so it names no version
			$context->report(
				$node->name,
				"Function `$function()` is deprecated"
				. ($deprecation->retiredIn === Config::MinPhpVersion ? '' : " since PHP $deprecation->retiredIn")
				. '.',
				fixable: false,
			);
		}
	}
}
