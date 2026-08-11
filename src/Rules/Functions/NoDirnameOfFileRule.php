<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentListNode;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Scalar\MagicConstantNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count;


/**
 * `__DIR__` instead of `dirname(__FILE__)`, and one `dirname()` with the levels argument instead of nested
 * calls: `dirname(dirname($x))` is `dirname($x, 2)` and `dirname(dirname(__FILE__))` is `dirname(__DIR__)`.
 */
#[RuleInfo(
	'dresscode/no-dirname-of-file',
	Stage::Structure,
	description: 'Replaces dirname(__FILE__) with __DIR__ and nested dirname() calls with the levels argument',
	group: Group::OptimizedCalls,
)]
final class NoDirnameOfFileRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$call = $resolver->isGlobalFunctionCall($node, 'dirname') ? self::parse($node) : null;
		if ($call === null) {
			return;
		}

		[$path, $levels] = $call;
		$inner = $path instanceof FunctionCallNode && $resolver->isGlobalFunctionCall($path, 'dirname') ? self::parse($path) : null;
		$uncertainty = NodeHelpers::findUncertainty($node, $context)
			?? ($inner !== null ? NodeHelpers::findUncertainty($path, $context) : null);
		$function = $node->name instanceof NameNode
			? NodeHelpers::spellGlobalFunction('dirname', $node->name, $context)
			: 'dirname';
		if ($inner !== null) {
			if (
				!$node->hasComment()
				&& $context->report($node, 'Nested dirname() calls must be one call with the levels argument' . $uncertainty, risky: $uncertainty !== null)
			) {
				self::replace($node, $inner[0], $levels + $inner[1], $function);
			}
		} elseif (
			self::isFile($path)
			&& !$node->hasComment()
			&& $context->report($node, "The dirname(__FILE__) call must be written '__DIR__'" . $uncertainty, risky: $uncertainty !== null)
		) {
			self::replace($node, $path, $levels, $function);
		}
	}


	/**
	 * The path argument and the levels of a dirname() call written in the plain way; null for any other.
	 * @return ?array{ExpressionNode, int}
	 */
	private static function parse(FunctionCallNode $call): ?array
	{
		$args = $call->arguments->items->getItems();
		foreach ($args as $arg) {
			if (!$arg instanceof ArgumentNode || $arg->name !== null || $arg->ellipsis !== null) {
				return null;
			}
		}

		$levels = $args[1] ?? null;
		return match (true) {
			count($args) === 1 => [$args[0]->value, 1],
			count($args) === 2 && $levels instanceof ArgumentNode && $levels->value instanceof IntegerNode && ctype_digit($levels->value->token->text)
				=> [$args[0]->value, (int) $levels->value->token->text],
			default => null,
		};
	}


	private static function isFile(ExpressionNode $expr): bool
	{
		return $expr instanceof MagicConstantNode && strcasecmp($expr->token->text, '__FILE__') === 0;
	}


	/** `dirname(path, levels)`, one level fewer from `__DIR__` when the path is `__FILE__`, the function spelled as given. */
	private static function replace(FunctionCallNode $node, ExpressionNode $path, int $levels, string $function): void
	{
		$parser = new Parser;
		if (self::isFile($path)) {
			$path = $parser->parseExpression('__DIR__');
			$levels--;
		} else {
			$path = $path->withoutEdgeTrivia();
		}

		if ($levels === 0) {
			$node->replaceWith($path);
			return;
		}

		$arguments = $levels === 1
			? ArgumentListNode::of($path)
			: ArgumentListNode::of($path, $parser->parseExpression((string) $levels));
		$node->replaceWith(FunctionCallNode::of(NameNode::fromText($function), $arguments));
	}
}
