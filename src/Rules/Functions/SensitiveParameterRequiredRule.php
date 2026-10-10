<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\ParameterNode;


/**
 * A parameter the project names as holding a secret carries `#[\SensitiveParameter]` of PHP 8.2, which keeps
 * its value out of stack traces. The list is the project's, because only the project knows what a name means
 * in it; the default holds the few names that mean a secret wherever they stand, and `token` is not among
 * them, a token being a piece of source as often as a credential.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.2'], analyses: [NameResolver::class])]
final class SensitiveParameterRequiredRule extends NodeRule
{
	/** @var list<string> */
	private array $parameters = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('upgrading.classes.SensitiveParameter', new Names, 'The names of the parameters holding a secret, whatever their letter case, which carry `#[\SensitiveParameter]` of PHP 8.2 to keep their values out of stack traces'),
		];
	}


	public function configure(Values $values): void
	{
		$this->parameters = array_map(strtolower(...), $values->get('upgrading.classes.SensitiveParameter')->getNames());
	}


	public function getVisitedNodes(): array
	{
		return [ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParameterNode
			|| ($name = $node->variable->plainName) === null
			|| !in_array(strtolower($name), $this->parameters, true)
			|| $context->getAnalysis(NameResolver::class)->hasAttribute($node, \SensitiveParameter::class)
			|| !$context->report($node, "The parameter `\$$name` must be marked with `#[\\SensitiveParameter]`, because it holds a secret.")
		) {
			return;
		}

		CodeWriter::addAttributes($node, [CodeWriter::writeClass(\SensitiveParameter::class, $node, $context)], $context, inline: true);
	}
}
