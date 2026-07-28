<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Token;
use function count;


/**
 * The PHP compiler turns calls of some functions into opcodes, but not when an argument is unpacked; reported.
 */
#[RuleInfo(
	'dresscode/no-unpacking-in-optimized-call',
	Stage::Structure,
	description: 'Reports argument unpacking in a call of a function the compiler optimizes',
)]
final class NoUnpackingInOptimizedCallRule extends Rule
{
	private const Functions = [
		'array_key_exists', 'array_slice', 'assert', 'boolval', 'call_user_func', 'call_user_func_array', 'chr',
		'constant', 'count', 'define', 'defined', 'dirname', 'doubleval', 'extension_loaded', 'floatval',
		'func_get_args', 'func_num_args', 'function_exists', 'get_called_class', 'get_class', 'gettype', 'in_array',
		'ini_get', 'intval', 'is_array', 'is_bool', 'is_callable', 'is_double', 'is_float', 'is_int', 'is_integer',
		'is_long', 'is_null', 'is_object', 'is_real', 'is_resource', 'is_scalar', 'is_string', 'ord', 'sizeof',
		'sprintf', 'strlen', 'strval',
	];


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
		$args = $node->args->args->getItems();
		$last = $args[count($args) - 1] ?? null;
		if (
			in_array($name, self::Functions, strict: true)
			&& $last instanceof ArgumentNode
			&& $last->ellipsis !== null
		) {
			$context->report($last, "Argument unpacking disables the compiler optimization of $name()");
		}
	}
}
