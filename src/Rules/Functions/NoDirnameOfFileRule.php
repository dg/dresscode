<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, GlobalCalls};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, ExpressionNode, NameNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Scalar\{IntegerNode, MagicConstantNode};
use function count;


/**
 * `__DIR__` instead of `dirname(__FILE__)`, and one `dirname()` with the levels argument instead of nested
 * calls: `dirname(dirname($x))` is `dirname($x, 2)` and `dirname(dirname(__FILE__))` is `dirname(__DIR__)`.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoDirnameOfFileRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('cleanup.dirnameOfFile', Domain::state('forbidden'), '`dirname(__FILE__)` is `__DIR__`; nested `dirname()` by the `levels` argument')];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode) {
			return;
		}

		$call = GlobalCalls::findFunction($node, ['dirname' => true], $context) !== null ? self::readCall($node) : null;
		if ($call === null || !$node->name instanceof NameNode) {
			return;
		}

		[$path, $levels] = $call;
		$inner = $path instanceof FunctionCallNode && GlobalCalls::findFunction($path, ['dirname' => true], $context) !== null ? self::readCall($path) : null;
		$uncertainty = GlobalCalls::findUncertainty($node, $context)
			?? ($inner !== null ? GlobalCalls::findUncertainty($path, $context) : null);
		$risk = $uncertainty === null ? null : Risk::NameUncertain;
		$function = CodeWriter::spellFunction('dirname', $node->name, $context);
		if ($inner !== null) {
			if (
				!$node->hasInnerComment()
				&& $context->report($node, 'Nested `dirname()` calls must be one call with the `levels` argument.', risk: $risk, because: $uncertainty)
			) {
				self::writeCall($node, $inner[0], $levels + $inner[1], $function);
			}
		} elseif (
			self::isFile($path)
			&& !$node->hasInnerComment()
			&& $context->report($node, 'The `dirname(__FILE__)` call must be written `__DIR__`.', risk: $risk, because: $uncertainty)
		) {
			self::writeCall($node, $path, $levels, $function);
		}
	}


	/**
	 * The path argument and the levels of a `dirname()` call written in the plain way; null for any other.
	 * @return ?array{ExpressionNode, int}
	 */
	private static function readCall(FunctionCallNode $call): ?array
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
			count($args) === 2 && $levels instanceof ArgumentNode && $levels->value instanceof IntegerNode
				=> [$args[0]->value, $levels->value->toValue()],
			default => null,
		};
	}


	private static function isFile(ExpressionNode $expr): bool
	{
		return $expr instanceof MagicConstantNode && strcasecmp($expr->token->text, '__FILE__') === 0;
	}


	/**
	 * `dirname(path, levels)`, one level fewer from `__DIR__` when the path is `__FILE__`, the function spelled
	 * as given.
	 */
	private static function writeCall(FunctionCallNode $node, ExpressionNode $path, int $levels, string $function): void
	{
		$builder = new Builder;
		if (self::isFile($path)) {
			$path = $builder->expression('__DIR__');
			$levels--;
		} else {
			$path = $path->withoutEdgeTrivia();
		}

		if ($levels === 0) {
			$node->replaceWith($path);
			return;
		}

		$node->replaceWith($builder->call($function, $levels === 1 ? [$path] : [$path, $levels]));
	}
}
