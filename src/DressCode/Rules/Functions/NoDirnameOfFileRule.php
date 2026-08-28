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
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Nodes\Scalar\MagicConstantNode;
use PhpSyntax\Parser\Parser;
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
)]
final class NoDirnameOfFileRule extends Rule
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
		if ($inner !== null) {
			if (
				!$node->hasComment()
				&& $context->report($node, 'Nested dirname() calls must be one call with the levels argument')
			) {
				self::replace($node, $inner[0], $levels + $inner[1]);
			}
		} elseif (
			self::isFile($path)
			&& !$node->hasComment()
			&& $context->report($node, "The dirname(__FILE__) call must be written '__DIR__'")
		) {
			self::replace($node, $path, $levels);
		}
	}


	/**
	 * The path argument and the levels of a dirname() call written in the plain way; null for any other.
	 * @return ?array{ExpressionNode, int}
	 */
	private static function parse(FunctionCallNode $call): ?array
	{
		$args = $call->args->args->getItems();
		foreach ($args as $arg) {
			if (!$arg instanceof ArgumentNode || $arg->name !== null || $arg->ellipsis !== null) {
				return null;
			}
		}

		$levels = $args[1] ?? null;
		return match (true) {
			count($args) === 1 => [$args[0]->expr, 1],
			count($args) === 2 && $levels instanceof ArgumentNode && $levels->expr instanceof IntegerNode && ctype_digit($levels->expr->token->text)
				=> [$args[0]->expr, (int) $levels->expr->token->text],
			default => null,
		};
	}


	private static function isFile(ExpressionNode $expr): bool
	{
		return $expr instanceof MagicConstantNode && strcasecmp($expr->token->text, '__FILE__') === 0;
	}


	/** `dirname(path, levels)`, one level fewer from `__DIR__` when the path is `__FILE__`. */
	private static function replace(FunctionCallNode $node, ExpressionNode $path, int $levels): void
	{
		$parser = new Parser;
		if (self::isFile($path)) {
			$path = $parser->parseExpression('__DIR__');
			$levels--;
		} else {
			$path = clone $path;
			$path->getFirstToken()?->setLeadingTrivia([]);
			$path->getLastToken()?->setTrailingTrivia([]);
		}

		if ($levels === 0) {
			$node->replaceWith($path);
			return;
		}

		$call = $parser->parseExpression($levels === 1 ? 'dirname(0)' : "dirname(0, $levels)");
		assert($call instanceof FunctionCallNode);
		$placeholder = $call->args->args->getItems()[0];
		assert($placeholder instanceof ArgumentNode);
		$placeholder->expr->replaceWith($path);
		$node->replaceWith($call);
	}
}
