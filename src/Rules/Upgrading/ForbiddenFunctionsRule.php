<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;


/**
 * Calls of the configured functions are reported, with what the map says to do instead where it says it. The name
 * is resolved the way PHP does, so `var_dump()` inside a namespace is the global function unless the file
 * declares or imports another one; a pattern with a backslash matches the fully qualified name.
 */
#[RuleInfo(
	Stage::Structure,
	decisions: ['upgrading.libraries.packageData', 'upgrading.libraries.forbiddenFunctions'],
	analyses: [NameResolver::class],
)]
final class ForbiddenFunctionsRule extends NodeRule
{
	public const Map = MemberMapGrammar::ForbiddenFunctions;

	/** @var list<array{string, ?string}>  regular expression, what to do instead */
	private array $functions = [];

	/** @var array<string, true>  lowercased functions withdrawn from the map, which no pattern reaches */
	private array $withdrawn = [];


	public function configure(Values $values): void
	{
		$options = $values->readMap(self::Map);
		$this->functions = [];
		foreach ($options as $pattern => $sentence) {
			$this->functions[] = ['~^' . str_replace('\*', '.*', preg_quote(ltrim((string) $pattern, '\\'), '~')) . '$~i', $sentence];
		}

		$this->withdrawn = array_fill_keys(
			array_map(fn($name) => strtolower(ltrim((string) $name, '\\')), $values->findWithdrawnNames(self::Map)),
			true,
		);
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($this->functions === [] || !$node instanceof FunctionCallNode || !$node->name instanceof NameNode || $node->name->isKeyword()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveFunction($node->name);
		if (isset($this->withdrawn[strtolower($resolved)])) {
			return;
		}

		foreach ($this->functions as [$pattern, $sentence]) {
			if (preg_match($pattern, $resolved)) {
				$context->report(
					$node->name,
					"Function `$resolved()` is forbidden" . ($sentence === null ? '' : ": $sentence") . '.',
					fixable: false,
				);
				return;
			}
		}
	}
}
