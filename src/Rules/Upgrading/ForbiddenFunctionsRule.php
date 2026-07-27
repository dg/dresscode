<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{ConfigurableRule, ConfigurationException, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use function is_array, is_int, is_string;


/**
 * Calls of the configured functions are reported, with what the map says to do instead where it says it. The name
 * is resolved the way PHP does, so `var_dump()` inside a namespace is the global function unless the file
 * declares or imports another one; a pattern with a backslash matches the fully qualified name.
 */
#[RuleInfo(
	'dresscode/forbiddenFunctions',
	Stage::Structure,
	description: 'Reports calls of the configured functions with what to do instead',
)]
final class ForbiddenFunctionsRule extends NodeRule implements ConfigurableRule
{
	/** @var list<array{string, ?string}>  regular expression, what to do instead */
	private array $functions = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::arrayOf(Expect::string()->nullable())
			->before(function (mixed $value) {
				if (is_array($value)) {
					foreach ($value as $key => $item) {
						if (is_int($key)) {
							$name = is_string($item) ? $item : 'var_dump';
							throw new ConfigurationException("`forbiddenFunctions` maps a function to a sentence; write `$name: null` for none.");
						}
					}
				}

				return $value;
			})
			->description('The forbidden function or a pattern with `*`, a name without a backslash meaning the global function → what to do instead, as the end of the message, or null for none');
	}


	public function configure(array $options): void
	{
		$this->functions = [];
		foreach ($options as $pattern => $sentence) {
			$this->functions[] = ['~^' . str_replace('\*', '.*', preg_quote(ltrim((string) $pattern, '\\'), '~')) . '$~i', $sentence];
		}
	}


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($this->functions === [] || !$node instanceof FunctionCallNode || !$node->name instanceof NameNode || $node->name->isKeyword()) {
			return;
		}

		$resolved = $context->getAnalysis(NameResolver::class)->resolveFunction($node->name);
		foreach ($this->functions as [$pattern, $sentence]) {
			if (preg_match($pattern, $resolved)) {
				$context->report($node->name, "Function `$resolved()` is forbidden" . ($sentence === null ? '' : ": $sentence"), fixable: false);
				return;
			}
		}
	}
}
