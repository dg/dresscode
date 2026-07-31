<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\PhpSymbols;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{NameKind, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use function count;


/**
 * A call of a function PHP optimizes is written in the form the optimization takes. The compiler turns calls
 * of some functions into opcodes, but only where it knows while compiling that the call is global, in the
 * global namespace or with the name imported or fully qualified, and never with an unpacked argument, which
 * is reported where the call would be optimized with the values passed one by one. PHP 8.4 calls some
 * functions without a frame, even unqualified in a namespace, but never with a named argument, which is
 * written positionally where every named argument stands at the position of its parameter and the code
 * targets 8.4 or later.
 */
#[RuleInfo(
	'dresscode/optimized-call-notation',
	Stage::Structure,
	description: 'Writes the arguments of a call PHP optimizes positionally and reports unpacking in it',
	group: Group::OptimizedCalls,
)]
final class OptimizedCallNotationRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| !$resolver->isGlobalFunctionCall($node)
		) {
			return;
		}

		// the function the name reaches, which an import may call by another name
		$name = strtolower($resolver->resolveFunction($node->name));
		$args = $node->arguments->items->getItems();
		$last = $args[count($args) - 1] ?? null;
		if ($last instanceof ArgumentNode && $last->ellipsis !== null) {
			if (
				(
					$node->name->kind === NameKind::FullyQualified
					|| $resolver->getNamespace($node) === ''
					|| isset($resolver->getFunctionImports($node)[strtolower($node->name->text)])
				)
				&& NodeHelpers::isOptimizedCall($node, $name, $context, unpacked: true)
			) {
				$context->report($last, "Argument unpacking disables the compiler optimization of $name()", fixable: false);
			}

			return;
		}

		$this->checkNamedArguments($node, $name, $args, $context);
	}


	/** @param  list<Node>  $args */
	private function checkNamedArguments(FunctionCallNode $node, string $name, array $args, RuleContext $context): void
	{
		if (version_compare($context->getPhpVersion(), '8.4', '<')) {
			return;
		}

		$parameters = $context->getAnalysis(PhpSymbols::class)->findFramelessParameters($name, count($args));
		$named = [];
		foreach ($args as $i => $arg) {
			if (!$arg instanceof ArgumentNode || $arg->ampersand !== null) {
				return;

			} elseif ($arg->name !== null) {
				if ($arg->name->text !== ($parameters[$i] ?? null)) {
					return;
				}

				$named[] = $arg;
			}
		}

		if ($named === []) {
			return;
		}

		// a comment between the name and the value would be lost with them
		$hasComment = array_any($named, fn(ArgumentNode $arg) => ($value = $arg->value->getFirstToken()) === null || $arg->name?->token->hasCommentUpTo($value));
		$uncertainty = $hasComment ? null : NodeHelpers::findUncertainty($node, $context);
		if (!$context->report(
			$named[0],
			"A named argument disables the compiler optimization of $name()" . $uncertainty,
			risky: $uncertainty !== null,
			fixable: !$hasComment,
		)) {
			return;
		}

		foreach ($named as $arg) {
			$arg->value->getFirstToken()?->setLeadingTrivia($arg->name?->token->leadingTrivia ?? []);
			$arg->name = null;
			$arg->colon = null;
		}
	}
}
