<?php declare(strict_types=1);

namespace Acme\DressCode\Rules;

use Acme\DressCode\Analyses\FunctionCalls;
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};


/**
 * Calls of debugging functions are reported.
 */
#[RuleInfo(Stage::Structure, decisions: ['acme.debugFunctions'], analyses: [FunctionCalls::class])]
final class NoVarDumpRule extends NodeRule
{
	/** @var list<string> */
	private array $functions = ['var_dump'];


	public static function getDecisions(): array
	{
		return [new Decision('acme.debugCalls', Domain::state('forbidden'), 'A call of a debugging function is not left in the code')];
	}


	public function configure(Values $values): void
	{
		$this->functions = $values->get('acme.debugFunctions')->getNames();
	}


	public function getVisitedNodes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		$calls = $context->getAnalysis(FunctionCalls::class);
		foreach ($this->functions as $function) {
			foreach ($calls->getCallsOf($function) as $call) {
				$context->report($call, "Call of $function() is forbidden.", fixable: false);
			}
		}
	}
}
