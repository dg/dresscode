<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{CodeWriter, GlobalCalls, NodeRule, QualifiedNames, RuleContext, RuleInfo, Stage, Values};
use DressCode\Config\Layer;
use DressCode\Rules\NodeHelpers;
use DressCode\Upgrading\{FunctionPattern, MemberMapGrammar};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;


/**
 * A call of a function a key of replacedCalls names, `utf8_encode($string)`, written as the template says, as
 * `ReplacedCallsRule` writes a call of a method, or with `removed` taken away with its statement. Which function the
 * call reaches is decided by its name, as for replacedFunctions, so no types are asked; a call of a bare name in the
 * template calls a global function, spelled the way the scope reaches one, and a call whose name may reach a function
 * of its namespace instead is risky. Of the keys of one function the one whose arguments have the more specific shape
 * decides.
 */
#[RuleInfo(
	Stage::Structure,
	decisions: ['upgrading.packages', 'upgrading.php.deprecatedApi', 'upgrading.api.replacedCalls'],
	analyses: [NameResolver::class],
)]
final class ReplacedFunctionCallsRule extends NodeRule
{
	/**
	 * @var array<lowercase-string, list<array{FunctionPattern, CallTemplate, string, ?Layer}>>  the function => its keys
	 * with the decision each is reported under and the section of the upgrading data it came with, the most specific first
	 */
	private array $functions = [];

	/** @var array<string, true>  the names of those functions without their namespace, which a call has to end with */
	private array $shortNames = [];


	public function configure(Values $values): void
	{
		$this->functions = $this->shortNames = [];
		foreach ($values->readMap(MemberMapGrammar::ReplacedCalls) as $key => $value) {
			if (MemberMapGrammar::isFunctionKey((string) $key)) {
				$pattern = FunctionPattern::fromKey((string) $key);
				$this->functions[$pattern->getLookupName()][] = [
					$pattern,
					CallTemplate::fromEntry($value, $pattern),
					$values->getEntryDecision(MemberMapGrammar::ReplacedCalls, (string) $key),
					$values->getEntryOrigin(MemberMapGrammar::ReplacedCalls, (string) $key),
				];
				$this->shortNames[strtolower(QualifiedNames::stripNamespace($pattern->name))] = true;
			}
		}

		foreach ($this->functions as $function => $entries) {
			usort($entries, fn(array $a, array $b) => $a[0]->getArgumentPattern()->compareSpecificity($b[0]->getArgumentPattern()));
			$this->functions[$function] = $entries;
		}
	}


	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class, FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		// a call standing as a statement is taken with the statement, which removing the call takes out
		$call = match (true) {
			$node instanceof ExpressionStatementNode => $node->expression,
			$node->parent instanceof ExpressionStatementNode => null,
			default => $node,
		};

		// a call is resolved only where its name is one of the map, or where the file imports a function by another name
		$function = $call instanceof FunctionCallNode
			&& $call->name instanceof NameNode
			&& (isset($this->shortNames[strtolower($call->name->shortName)]) || CodeWriter::importsFunctionAs($context))
			&& !$call->arguments->isPartialApplication()
			? NodeHelpers::findMappedFunction($call, $this->functions, $context)
			: null;
		if ($function === null) {
			return;
		}

		foreach ($this->functions[$function] as [$pattern, $template, $decision, $source]) {
			$bindings = $pattern->bind($call->arguments);
			if ($bindings === null) {
				continue;
			}

			$subject = "Function `$pattern->name()`";
			$uncertainty = str_contains($function, '\\') ? null : GlobalCalls::findUncertainty($call, $context);
			if ($template->removesCall()) {
				$template->removeCall($call, $subject, $decision, $source, $context, $uncertainty);
				return;
			}

			$rewrite = $template->instantiate($bindings, $call->arguments, null);
			if (
				$context->report(
					$call->name,
					$template->describe($subject) . "$rewrite->refusal.",
					decision: $decision,
					source: $source,
					fixable: $rewrite->expression !== null,
					risk: $rewrite->expression === null ? null : NodeHelpers::findRisk($rewrite->because, $uncertainty),
					because: $rewrite->expression === null ? null : $rewrite->because ?? $uncertainty,
				)
				&& $rewrite->expression !== null
			) {
				$call->replaceWithExpression($rewrite->write($call, $context));
			}

			return;
		}
	}
}
