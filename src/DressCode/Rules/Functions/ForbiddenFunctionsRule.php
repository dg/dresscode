<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;
use function is_int;


/**
 * Calls of the configured functions are reported, with the suggested replacement when one is given. The name
 * is resolved the way PHP does, so `var_dump()` inside a namespace is the global function unless the file
 * declares or imports another one; a pattern with a backslash matches the fully qualified name.
 */
#[RuleInfo(
	'dresscode/forbidden-functions',
	Stage::Structure,
	description: 'Reports calls of the configured functions',
)]
final class ForbiddenFunctionsRule extends Rule implements ConfigurableRule
{
	/** @var list<array{string, ?string}>  regular expression, replacement to suggest */
	private array $functions = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'functions' => Expect::arrayOf('?string', 'string|int')
				->description('Names or patterns with *, or name => replacement to suggest as written; a name without a backslash means the global function'),
		]);
	}


	public function configure(array $options): void
	{
		$this->functions = [];
		foreach ($options['functions'] as $name => $replacement) {
			[$pattern, $replacement] = is_int($name) ? [(string) $replacement, null] : [$name, $replacement];
			$this->functions[] = ['~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . '$~i', $replacement];
		}
	}


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode || !$node->name instanceof NameNode || $node->name->isKeyword()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveFunction($node->name);
		foreach ($this->functions as [$pattern, $replacement]) {
			if (preg_match($pattern, $resolved)) {
				$context->report($node->name, "Function $resolved() is forbidden" . ($replacement === null ? '' : ", the replacement is $replacement"));
				return;
			}
		}
	}
}
