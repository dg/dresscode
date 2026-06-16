<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use DressCode\{ConfigurationException, GapRule, NodeRule, Rule, RuleInfo, Stage};
use DressCode\Engine\Gaps\Claims;
use PhpSyntax\{Node, Token};


/**
 * What the rules of a configuration are to the passes, whatever the file: the node rules of each stage, which of
 * them each class of node or token is dispatched to, and the claims of the gap rules. Made once and shared by the
 * runners of the files.
 * @internal
 */
final class RulePlan
{
	/** @var array<string, list<NodeRule>>  stage name => node rules in configuration order */
	public readonly array $stages;

	/** @var array<string, bool>  stage => some rule of it overrides `leave()` */
	public readonly array $leaves;

	public readonly Claims $claims;

	/** @var array<string, array<class-string, list<NodeRule>>>  `'enter Stage'` or `'leave Stage'` => class => the rules it is dispatched to */
	private array $dispatch = [];


	/**
	 * @throws ConfigurationException when a rule is of neither kind, visits a type no node is, or the claims of the gap
	 *   rules do not fit the tree or each other
	 */
	public function __construct(
		/** @var list<Rule> in configuration order */
		public readonly array $rules,
	) {
		$stages = $leaves = [];
		foreach (Stage::cases() as $stage) {
			$stages[$stage->name] = [];
			$leaves[$stage->name] = false;
		}

		foreach ($rules as $rule) {
			$info = RuleInfo::of($rule);
			if ($rule instanceof NodeRule) {
				self::checkVisitedTypes($rule, $info);
				$stage = $info->stage->name;
				$stages[$stage][] = $rule;
				$leaves[$stage] = $leaves[$stage] || self::overrides($rule, 'leave');
			} elseif (!$rule instanceof GapRule) {
				throw new ConfigurationException("Rule `$info->name` is neither a NodeRule nor a GapRule.");
			} elseif ($info->stage !== Stage::Formatting) {
				throw new ConfigurationException("Rule `$info->name` is a GapRule, whose claims the engine settles in the stage Formatting, but it says {$info->stage->name}.");
			}
		}

		$this->stages = $stages;
		$this->leaves = $leaves;
		$this->claims = new Claims($rules);
	}


	/**
	 * The rules of the stage whose `enter()` or `leave()` wants the class of the node, remembered for the next node of it.
	 * @param class-string<Node|Token> $class
	 * @return list<NodeRule>
	 */
	public function getRulesVisiting(string $stage, string $class, bool $enter): array
	{
		$method = $enter ? 'enter' : 'leave';
		if (isset($this->dispatch["$method $stage"][$class])) {
			return $this->dispatch["$method $stage"][$class];
		}

		$rules = [];
		foreach ($this->stages[$stage] as $rule) {
			if (self::overrides($rule, $method) && array_any($rule->getVisitedTypes(), fn(string $type) => is_a($class, $type, true))) {
				$rules[] = $rule;
			}
		}

		return $this->dispatch["$method $stage"][$class] = $rules;
	}


	/** @throws ConfigurationException when a type the rule visits is no class of a node or token, which would never be dispatched */
	private static function checkVisitedTypes(NodeRule $rule, RuleInfo $info): void
	{
		foreach ($rule->getVisitedTypes() as $type) {
			if (!is_a($type, Node::class, true) && !is_a($type, Token::class, true) && !interface_exists($type)) {
				throw new ConfigurationException("Rule `$info->name` visits `$type`, which is no class of a node or a token.");
			}
		}
	}


	private static function overrides(NodeRule $rule, string $method): bool
	{
		static $cache = [];
		return $cache[$rule::class][$method] ??= new \ReflectionMethod($rule, $method)->getDeclaringClass()->getName() !== NodeRule::class;
	}
}
