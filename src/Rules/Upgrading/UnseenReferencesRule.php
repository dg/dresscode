<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Config\Layer;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Upgrading\MemberMapGrammar;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Expression\{FunctionCallNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode};
use PhpSyntax\Nodes\{ExpressionNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Scalar\StringNode;


/**
 * The places of the code an upgrade does not see, reported and never rewritten, so that the estimate of an upgrade
 * counts them: a string holding the name of a class the maps rename or forbid, which a rename does not reach, and a
 * member, a class or a function reached by a name the code computes, which no map of members can match.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class], reads: [self::Replaced, self::Forbidden])]
final class UnseenReferencesRule extends NodeRule
{
	public const ClassNameString = 'upgrading.unseen.classNameString';
	public const DynamicAccess = 'upgrading.unseen.dynamicAccess';
	private const Replaced = MemberMapGrammar::ReplacedClasses;
	private const Forbidden = MemberMapGrammar::ForbiddenClasses;

	/** the functions calling what their first argument names */
	private const CallingFunctions = ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array'];

	/**
	 * @var array<string, array{string, string, ?Layer}>  lowercased class => the class as the map spells it, what the map
	 * does with it, the section of the upgrading data that laid it
	 */
	private array $classes = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(self::ClassNameString, new Words([
				'reported' => 'reported where it stands: the string `\'Acme\Old\'` naming a class the maps rename or forbid',
			]), 'A string holding the name of a class `replacedClasses` or `forbiddenClasses` names, which no rename of the class reaches'),
			new Decision(self::DynamicAccess, new Words([
				'reported' => 'reported where it stands: `$object->$method()`, `new $class`, `call_user_func($callback)`',
			]), 'A member, a class or a function reached by a name the code computes, which no map of upgrading data matches'),
		];
	}


	public function configure(Values $values): void
	{
		$this->classes = [];
		foreach ([self::Forbidden => 'forbidden', self::Replaced => 'replaced'] as $path => $verdict) {
			foreach ($values->readMap($path) as $class => $value) {
				$class = ltrim((string) $class, '\\');
				$this->classes[strtolower($class)] = [
					$class,
					$verdict === 'replaced' ? 'replaced by `' . ltrim((string) $value, '\\') . '`' : 'forbidden',
					$values->getEntryOrigin($path, $class),
				];
			}
		}
	}


	public function getVisitedNodes(): array
	{
		return [
			...$this->classes === [] ? [] : [StringNode::class],
			MethodCallNode::class,
			StaticMethodCallNode::class,
			PropertyFetchNode::class,
			NewNode::class,
			FunctionCallNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof StringNode) {
			$this->checkString($node, $context);
			return;
		}

		$subject = match (true) {
			$node instanceof MethodCallNode && !$node->name instanceof IdentifierNode => 'A method called',
			$node instanceof PropertyFetchNode && !$node->name instanceof IdentifierNode => 'A property read',
			$node instanceof StaticMethodCallNode && (!$node->name instanceof IdentifierNode || !$node->class instanceof NameNode)
				=> 'A static method called',
			$node instanceof NewNode && $node->class instanceof ExpressionNode => 'A class instantiated',
			$node instanceof FunctionCallNode && (!$node->name instanceof NameNode || $this->callsComputedName($node, $context))
				=> 'A function called',
			default => null,
		};
		if ($subject !== null) {
			$context->report(
				$node,
				"$subject by a name the code computes, which no map of upgrading data sees.",
				decision: self::DynamicAccess,
				fixable: false,
			);
		}
	}


	private function checkString(StringNode $node, RuleContext $context): void
	{
		[$class, $verdict, $source] = $this->classes[strtolower(ltrim($node->toValue(), '\\'))] ?? [null, null, null];
		if ($class !== null) {
			$context->report(
				$node,
				"The string names class `$class`, which is $verdict; no rewrite reaches a string.",
				decision: self::ClassNameString,
				fixable: false,
				source: $source,
			);
		}
	}


	/** Whether the call is of a function calling what its first argument names, given as other than a literal. */
	private function callsComputedName(FunctionCallNode $node, RuleContext $context): bool
	{
		assert($node->name instanceof NameNode);
		$function = strtolower($context->getAnalysis(NameResolver::class)->resolveFunction($node->name));
		$first = $node->arguments->findArgument(null, 0);
		return in_array($function, self::CallingFunctions, true) && $first !== null && !$first->value instanceof StringNode;
	}
}
