<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\{AttributeGroupNode, NameNode, ParameterNode};
use function in_array;


/**
 * A parameter the project names as holding a secret carries `#[\SensitiveParameter]` of PHP 8.2, which keeps
 * its value out of stack traces. The list is the project's, because only the project knows what a name means
 * in it; the default holds the few names that mean a secret wherever they stand, and `token` is not among
 * them, a token being a piece of source as often as a credential.
 */
#[RuleInfo(
	'dresscode/sensitive-parameter-required',
	Stage::Structure,
	description: 'Marks the parameters the configuration names with #[\SensitiveParameter]',
	requires: ['php' => '>=8.2'],
	decision: 'parameters',
)]
final class SensitiveParameterRequiredRule extends NodeRule implements ConfigurableRule
{
	/** @var list<string> */
	private array $parameters = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'parameters' => Expect::listOf('string')
				->default(['password', 'passwd', 'pwd', 'secret', 'apiKey', 'api_key', 'privateKey', 'private_key'])
				->description('Names of the parameters that hold a secret, whatever their letter case'),
		]);
	}


	public function configure(array $options): void
	{
		$this->parameters = array_values(array_map(strtolower(...), $options['parameters']));
	}


	public function getVisitedTypes(): array
	{
		return [ParameterNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ParameterNode
			|| ($name = $node->variable->plainName) === null
			|| !in_array(strtolower($name), $this->parameters, true)
			|| self::isMarked($node, $context)
			|| !$context->report($node, 'The parameter holding a secret must be marked with #[\SensitiveParameter]')
		) {
			return;
		}

		$template = (new Parser)->parseFragment(ParameterNode::class, '#[\SensitiveParameter] $template');
		$group = $template->attributes->getItems()[0];
		$template->attributes->removeItem($group);
		$first = ($node->modifiers->getTokens()[0] ?? null) ?? ($node->type ?? $node->variable)->getFirstToken();
		if ($first !== null) {
			// the attribute takes over what stood in front of the parameter and brings its own space along
			$group->getFirstToken()?->setLeadingTrivia($first->leadingTrivia);
			$first->setLeadingTrivia([]);
		}

		$node->attributes->append($group);
	}


	private static function isMarked(ParameterNode $parameter, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return array_any(
			$parameter->find(NameNode::class),
			fn(NameNode $name) => $name->findAncestor(AttributeGroupNode::class) !== null
				&& strcasecmp($resolver->resolveClass($name), 'SensitiveParameter') === 0,
		);
	}
}
