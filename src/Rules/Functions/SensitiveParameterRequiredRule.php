<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, DecisionKind, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\ParameterNode;


/**
 * A parameter whose name says it holds a secret carries `#[\SensitiveParameter]` of PHP 8.2, which keeps its value
 * out of stack traces. The names are a list a project extends, because only the project knows what a name means
 * in it.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.2'], analyses: [NameResolver::class])]
final class SensitiveParameterRequiredRule extends NodeRule
{
	/** @var list<string> */
	private array $parameters = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'upgrading.classes.SensitiveParameter',
				Domain::state('required'),
				'`#[\SensitiveParameter]` of PHP 8.2 on a parameter holding a secret, which keeps its value out of stack traces',
			),
			new Decision(
				'upgrading.classes.SensitiveParameterNames',
				new Names(empty: false),
				'The names of the parameters holding a secret, whatever their letter case',
				kind: DecisionKind::Parameter,
				default: ['password', 'passphrase', 'secret', 'apiKey', 'privateKey'],
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->parameters = array_map(strtolower(...), $values->get('upgrading.classes.SensitiveParameterNames')->getNames());
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
