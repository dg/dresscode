<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\{Parameter, PhpSignatures, Types};
use DressCode\{Decision, Domain, GlobalCalls, NodeRule, Risk, RuleContext, RuleInfo, Stage, Tristate};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use function count;


/**
 * PHP 8.1 deprecated passing null to a parameter of a function of PHP that takes no null, which PHP turned into the
 * empty value of the type: `trim($text ?? '')` says what `trim($text)` did with a null, on every version. Only a
 * parameter of a scalar type is about it; a parameter of `mixed`, of a nullable type or of an array alone takes null
 * or refuses it outright. An argument the types tell is a scalar or null is rewritten, which changes nothing PHP did;
 * one of a type they do not know is left, since nearly every value of `mixed` may be null, and one that is always
 * null is reported, having no value worth keeping.
 */
#[RuleInfo(Stage::Structure, typesRequired: true, analyses: [Types::class, PhpSignatures::class, NameResolver::class])]
final class NoNullArgumentsRule extends NodeRule
{
	/** the empty value PHP turns a null into for each scalar type, in the order it prefers them in a union */
	private const EmptyValues = ['int' => '0', 'float' => '0.0', 'string' => "''", 'bool' => 'false'];


	public static function getDecisions(): array
	{
		return [new Decision(
			'upgrading.php.nullArgument',
			Domain::state('forbidden'),
			'`trim($text ?? \'\')`, where `trim($text)` passes a null to a parameter that takes none, deprecated by PHP 8.1',
		)];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| $node->arguments->isPartialApplication()
			|| !$resolver->isGlobalFunctionCall($node)
		) {
			return;
		}

		$function = $resolver->resolveFunction($node->name);
		$parameters = $context->getAnalysis(PhpSignatures::class)->findParameters($function);
		foreach ($node->arguments->items->getItems() as $position => $argument) {
			$parameter = $parameters === null || !$argument instanceof ArgumentNode || $argument->ellipsis !== null
				? null
				: self::findParameter($parameters, $argument, $position);
			$empty = $parameter === null ? null : self::findEmptyValue($parameter);
			if ($parameter !== null && $empty !== null) {
				$this->checkArgument($node, $argument, $parameter, $empty, "$function()", $context);
			}
		}
	}


	private function checkArgument(
		FunctionCallNode $call,
		ArgumentNode $argument,
		Parameter $parameter,
		string $empty,
		string $function,
		RuleContext $context,
	): void
	{
		$types = $context->getAnalysis(Types::class);
		$value = $argument->value;
		$null = $types->isOfType($value, 'null');
		if ($null === Tristate::No) {
			return;
		} elseif ($null === Tristate::Yes) {
			$context->report(
				$value,
				"The argument for `\$$parameter->name` of `$function` is null, which PHP 8.1 deprecated for type `$parameter->type`.",
				fixable: false,
			);
			return;
		}

		if ($types->isOfType($value, 'scalar|null') !== Tristate::Yes) {
			return;
		}

		$uncertainty = GlobalCalls::findUncertainty($call, $context);
		if ($context->report(
			$value,
			"The argument for `\$$parameter->name` of `$function` may be null, which PHP 8.1 deprecated for type `$parameter->type`.",
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			$argument->value = (new Builder)->expression("\$value ?? $empty", value: $value->withoutEdgeTrivia());
		}
	}


	/**
	 * The parameter the argument is passed to: by its name, else by its position, the variadic one taking the rest.
	 * @param  list<Parameter>  $parameters
	 */
	private static function findParameter(array $parameters, ArgumentNode $argument, int $position): ?Parameter
	{
		if ($argument->name !== null) {
			return array_find($parameters, fn(Parameter $parameter) => $parameter->name === $argument->name->text);
		}

		$last = $parameters[count($parameters) - 1] ?? null;
		return $parameters[$position] ?? ($last?->variadic ? $last : null);
	}


	/**
	 * The value PHP made of a null passed to the parameter: the empty value of its scalar type; null for a parameter that
	 * takes null, takes anything, takes it by reference, or has no scalar type.
	 */
	private static function findEmptyValue(Parameter $parameter): ?string
	{
		$types = $parameter->type === null ? [] : explode('|', strtolower(ltrim($parameter->type, '?')));
		if ($parameter->byReference || str_starts_with((string) $parameter->type, '?') || array_intersect($types, ['null', 'mixed'])) {
			return null;
		}

		return array_find(self::EmptyValues, fn($empty, $type) => in_array($type, $types, true));
	}
}
