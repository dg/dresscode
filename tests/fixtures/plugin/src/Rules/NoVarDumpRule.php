<?php declare(strict_types=1);

namespace Acme\DressCode\Rules;

use Acme\DressCode\Analyses\FunctionCalls;
use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;


/**
 * Calls of debugging functions are reported.
 */
#[RuleInfo('acme/no-var-dump', Stage::Structure, description: 'Forbids debugging function calls')]
final class NoVarDumpRule extends Rule implements ConfigurableRule
{
	/** @var list<string> */
	private array $functions = ['var_dump'];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'functions' => Expect::listOf('string')->default(['var_dump']),
		]);
	}


	public function configure(array $options): void
	{
		$this->functions = $options['functions'];
	}


	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforeFile(RuleContext $context): void
	{
		$calls = $context->getAnalysis(FunctionCalls::class);
		foreach ($this->functions as $function) {
			foreach ($calls->getCallsOf($function) as $call) {
				$context->report($call, "Call of $function() is forbidden");
			}
		}
	}
}
