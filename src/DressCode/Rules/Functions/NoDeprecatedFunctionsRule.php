<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;


/**
 * Calls of functions the running PHP marks as deprecated; the list comes from the runtime running the check.
 */
#[RuleInfo(
	'dresscode/no-deprecated-functions',
	Stage::Structure,
	description: 'Reports calls of deprecated internal functions',
)]
final class NoDeprecatedFunctionsRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| !$context->getAnalysis(NameResolver::class)->isGlobalFunctionCall($node)
		) {
			return;
		}

		$name = strtolower($node->name->getParts()[0]);
		if (isset(self::getDeprecatedFunctions()[$name])) {
			$context->report($node->name, "Function $name() is deprecated");
		}
	}


	/** @return array<string, true>  lowercased names of the deprecated internal functions of the runtime */
	private static function getDeprecatedFunctions(): array
	{
		static $functions = null;
		if ($functions === null) {
			$functions = [];
			foreach (get_defined_functions()['internal'] as $function) {
				if ((new \ReflectionFunction($function))->isDeprecated()) {
					$functions[strtolower($function)] = true;
				}
			}
		}

		return $functions;
	}
}
