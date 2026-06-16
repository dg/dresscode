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

	/** @var array<class-string, Gate>  the class of a rule => the gate of its reports */
	public readonly array $gates;

	/** @var array<string, array<class-string, list<NodeRule>>>  `'enter Stage'` or `'leave Stage'` => class => the rules it is dispatched to */
	private array $dispatch = [];


	/**
	 * @param  array<class-string, Gate>  $gates  the class of a rule => the gate of its reports; a rule without one reports every requirement
	 * @throws ConfigurationException when a rule declares no decision, is of neither kind, visits a class no node is, or
	 *   the claims of the gap rules do not fit the tree or each other
	 */
	public function __construct(
		/** @var list<Rule> in configuration order */
		public readonly array $rules,
		array $gates = [],
	) {
		$stages = $leaves = [];
		foreach (Stage::cases() as $stage) {
			$stages[$stage->name] = [];
			$leaves[$stage->name] = false;
		}

		foreach ($rules as $rule) {
			$decisions = $rule::getDecisions();
			if ($decisions === []) {
				throw new ConfigurationException('Rule `' . $rule::class . '` declares no decision.');
			}

			$gates[$rule::class] ??= Gate::open($decisions);
			$info = RuleInfo::of($rule);
			if ($rule instanceof NodeRule) {
				self::checkVisitedNodes($rule);
				$stage = $info->stage->name;
				$stages[$stage][] = $rule;
				$leaves[$stage] = $leaves[$stage] || self::overrides($rule, 'leave');
			} elseif (!$rule instanceof GapRule) {
				throw new ConfigurationException('Rule `' . $rule::class . '` is neither a NodeRule nor a GapRule.');
			} elseif ($info->stage !== Stage::Formatting) {
				throw new ConfigurationException('Rule `' . $rule::class . "` is a GapRule, whose claims the engine settles in the stage Formatting, but it says {$info->stage->name}.");
			}
		}

		$this->stages = $stages;
		$this->leaves = $leaves;
		$this->gates = $gates;
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
			if (self::overrides($rule, $method) && array_any($rule->getVisitedNodes(), fn(string $visited) => is_a($class, $visited, true))) {
				$rules[] = $rule;
			}
		}

		return $this->dispatch["$method $stage"][$class] = $rules;
	}


	/** @throws ConfigurationException when a class the rule visits is no class of a node or token, which would never be dispatched */
	private static function checkVisitedNodes(NodeRule $rule): void
	{
		foreach ($rule->getVisitedNodes() as $visited) {
			if (!is_a($visited, Node::class, true) && !is_a($visited, Token::class, true) && !interface_exists($visited)) {
				throw new ConfigurationException('Rule `' . $rule::class . "` visits `$visited`, which is no class of a node or a token.");
			}
		}
	}


	/** Whether the rule acts in the callback, `enter` or `leave`, rather than inheriting the empty one. */
	private static function overrides(NodeRule $rule, string $method): bool
	{
		static $cache = [];
		return $cache[$rule::class][$method] ??= new \ReflectionMethod($rule, $method)->getDeclaringClass()->getName() !== NodeRule::class;
	}
}
