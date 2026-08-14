<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Namespaces;

use DressCode\Analyses\{Parameter, PhpSignatures, PhpSymbols};
use DressCode\{NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Rules\{Compiler, GlobalCalls};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use function count;


/**
 * The arguments of a call of a global function the decisions write qualified for the compiler, `optimizedFunction` or,
 * where that key requires no form, `globalFunction`, in the form the optimization takes. The compiler turns calls of
 * some functions into opcodes, but only where it knows while compiling that the call is global, in the global namespace
 * or with the name imported or fully qualified, and never with an unpacked argument, which is reported where the call
 * would be optimized with the values passed one by one, nor with a named one. PHP 8.4 calls some functions without a
 * frame, even unqualified in a namespace, but never with a named argument either. A named argument is written
 * positionally where every named argument stands at the position of its parameter, whatever version the code targets,
 * since it may run on a later one.
 */
#[RuleInfo(
	Stage::Structure,
	decisions: ['qualification.globalFunction', 'qualification.optimizedFunction'],
	analyses: [PhpSignatures::class, PhpSymbols::class, NameResolver::class],
)]
final class OptimizedCallNotationRule extends NodeRule
{
	/** the decision that writes an optimized function qualified, null where none does */
	private ?string $decision = null;


	public function configure(Values $values): void
	{
		foreach (['qualification.optimizedFunction', 'qualification.globalFunction'] as $decision) {
			$forms = $values->find($decision)?->getWords();
			if ($forms !== null) {
				$this->decision = in_array($forms[0], [QualificationPolicy::Imported, QualificationPolicy::Backslashed], true) ? $decision : null;
				return;
			}
		}
	}


	public function getVisitedNodes(): array
	{
		return $this->decision === null ? [] : [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof FunctionCallNode || !$node->name instanceof NameNode || $this->decision === null) {
			return;
		}

		// only an unpacked or a named argument keeps the compiler from optimizing a call
		$args = $node->arguments->items->getItems();
		$unpacked = array_find($args, fn($arg) => $arg instanceof ArgumentNode && $arg->ellipsis !== null);
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			($unpacked === null && !array_any($args, fn($arg) => $arg instanceof ArgumentNode && $arg->name !== null))
			|| !$resolver->isGlobalFunctionCall($node)
		) {
			return;
		}

		// the function the name reaches, which an import may call by another name
		$name = strtolower($resolver->resolveFunction($node->name));

		// the compiler optimizes only a call it knows to be global while compiling
		$qualified = Compiler::isNameKnown($node->name, SymbolKind::Function, $context);
		if ($unpacked !== null) {
			if ($qualified && Compiler::isOptimizedCall($node, $name, $context, unpacked: true)) {
				$context->report($unpacked, "Argument unpacking disables the compiler optimization of `$name()`.", decision: $this->decision, fixable: false);
			}

			return;
		}

		$parameters = $context->getAnalysis(PhpSymbols::class)->findFramelessParameterNames($name, count($args))
			?? ($qualified && Compiler::isOptimizedCall($node, $name, $context, named: true)
				? array_map(fn(Parameter $parameter) => $parameter->variadic ? '' : $parameter->name, $context->getAnalysis(PhpSignatures::class)->findParameters($name) ?? [])
				: []);
		$this->writePositionally($node, $name, $args, $parameters, $this->decision, $context);
	}


	/**
	 * @param  list<Node>  $args
	 * @param  list<string>  $parameters  the names of the parameters in their order, a variadic one taking no named argument
	 */
	private function writePositionally(
		FunctionCallNode $node,
		string $name,
		array $args,
		array $parameters,
		string $decision,
		RuleContext $context,
	): void
	{
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
		$hasComment = array_any($named, fn(ArgumentNode $arg) => $arg->name?->token->hasCommentUpTo($arg->value->getFirstToken()) === true);
		$uncertainty = $hasComment ? null : GlobalCalls::findUncertainty($node, $context);
		if (!$context->report(
			$named[0],
			"A named argument disables the compiler optimization of `$name()`.",
			decision: $decision,
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			fixable: !$hasComment,
			because: $uncertainty,
		)) {
			return;
		}

		foreach ($named as $arg) {
			$arg->value->getFirstToken()->setLeadingTrivia($arg->name?->token->leadingTrivia ?? []);
			$arg->name = null;
			$arg->colon = null;
		}
	}
}
