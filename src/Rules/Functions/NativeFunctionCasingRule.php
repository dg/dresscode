<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\PhpSymbols;
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use function strlen;


/**
 * Functions defined by PHP called in lowercase: `strlen()`, not `StrLen()`. A global function of the project, or of
 * an extension PHP does not ship, keeps the case it is written in.
 */
#[RuleInfo(
	'dresscode/native-function-casing',
	Stage::Structure,
	description: 'Calls native functions in lowercase',
)]
final class NativeFunctionCasingRule extends NodeRule
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

		$written = $node->name->shortName;
		$lower = strtolower($written);
		if (
			$written === $lower
			// an alias of an import is a name of the project, not the name of the function it calls
			|| strcasecmp($resolver->resolveFunction($node->name), $written) !== 0
			|| !$context->getAnalysis(PhpSymbols::class)->isInternalFunction($lower)
		) {
			return;
		}

		if ($context->report($node->name, "The function $written() must be written '$lower()'")) {
			$token = $node->name->token;
			$token->setText(substr($token->text, 0, strlen($token->text) - strlen($written)) . $lower);
		}
	}
}
