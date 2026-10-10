<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\PhpSignatures;
use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentNode, FileNode, NameNode, ParameterNode, PlainNodeList};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Statement\{FunctionNode, NamespaceNode};
use function count, is_bool;


/**
 * An argument the call does the same without goes. That is one PHP has stopped reading and then deprecated, which
 * the version that removes the parameter will refuse: only one standing last is taken, so that no other argument
 * moves, and only one that would do nothing when it ran, so that dropping it drops nothing; the `true` that
 * `define()` ignores with a warning goes too, and the warning with it. And it is one written as the very value
 * its parameter defaults to, `json_encode($data, 0)`: named anywhere, or by position at the end of the call. The
 * defaults are those of a function of PHP every version from 8.0 on agrees on, and of a function declared at the top of
 * the file unless it counts its arguments; one declared under a condition or in a function body may give way to another
 * declaration of the name, and a method is left alone, a child being free to declare another default.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpSignatures::class, NameResolver::class])]
final class RedundantArgumentsRule extends NodeRule
{
	/** function => the name of the parameter, the position it stands at, and the version that deprecated it */
	private const Ignored = [
		'define' => ['case_insensitive', 2, '8.6'],
		'finfo_buffer' => ['context', 3, '8.5'],
		'get_defined_functions' => ['exclude_disabled', 0, '8.5'],
	];

	/** functions whose behaviour depends on how many arguments they were given */
	private const CountingFunctions = ['func_get_arg' => true, 'func_get_args' => true, 'func_num_args' => true];


	public static function getDecisions(): array
	{
		return [new Decision('cleanup.redundantArguments', Domain::state('forbidden'), 'An argument PHP ignores or that repeats the default')];
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionCallNode
			|| $node->arguments->items->isEmpty()
			|| $node->arguments->isPartialApplication()
			|| $node->hasInnerComment()
		) {
			return;
		}

		// the ignored argument, which only the last one may be, goes first, so that the defaults before it end the call
		self::removeIgnored($node, $context);
		self::removeDefaults($node, $context);
	}


	private static function removeIgnored(FunctionCallNode $call, RuleContext $context): void
	{
		$function = GlobalCalls::findFunction($call, self::Ignored, $context);
		if ($function === null) {
			return;
		}

		[$parameter, $position, $since] = self::Ignored[$function];
		$argument = $call->arguments->findArgument($parameter, $position);
		$items = $call->arguments->items->getItems();
		if (
			$argument !== null
			&& $argument === end($items)
			&& ($argument->value->isRepeatableRead() || $argument->value->hasValue())
			&& $context->report(
				$argument,
				"Useless `$parameter` argument of `$function()`, because it is ignored and deprecated since PHP $since.",
				risk: ($uncertainty = GlobalCalls::findUncertainty($call, $context)) === null ? null : Risk::NameUncertain,
				because: $uncertainty,
			)
		) {
			$call->arguments->items->removeItem($argument);
		}
	}


	private static function removeDefaults(FunctionCallNode $call, RuleContext $context): void
	{
		$items = $call->arguments->items->getItems();
		$last = $items[count($items) - 1] ?? null;
		if (
			$last === null
			|| !array_any($items, fn(Node $item) => $item instanceof ArgumentNode && ($item->name !== null || $item === $last) && $item->value->hasValue())
			|| ($found = self::findDefaults($call, $context)) === null
		) {
			return;
		}

		[$function, $defaults, $risky] = $found;
		$removed = [];
		foreach ($items as $item) { // named, anywhere
			if ($item instanceof ArgumentNode && $item->name !== null && self::repeatsDefault($item, $defaults[$item->name->text] ?? null)) {
				$removed[] = [$item, $item->name->text];
			}
		}

		$names = array_keys($defaults);
		foreach (array_reverse(array_keys($items)) as $position) { // by position, from the end
			$item = $items[$position];
			if ($item instanceof ArgumentNode && $item->name !== null) {
				continue;
			} elseif (
				!$item instanceof ArgumentNode
				|| $item->ellipsis !== null
				|| !self::repeatsDefault($item, $defaults[$names[$position] ?? ''] ?? null)
			) {
				break;
			}
			$removed[] = [$item, $names[$position]];
		}

		foreach ($removed as [$argument, $name]) {
			if ($context->report(
				$argument,
				"Useless `$name` argument of `$function()`, because it repeats the default value.",
				risk: $risky === null ? null : Risk::NameUncertain,
				because: $risky,
			)) {
				$call->arguments->items->removeItem($argument);
			}
		}
	}


	/**
	 * The function the call calls, its parameters with the default of each as PHP code, null for one without a default
	 * to compare or a variadic one, and why the function may be another one.
	 * @return ?array{string, array<string, ?string>, ?string}
	 */
	private static function findDefaults(FunctionCallNode $call, RuleContext $context): ?array
	{
		if (!$call->name instanceof NameNode) {
			return null;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$function = $resolver->resolveFunction($call->name);
		$declaration = $resolver->findDeclaration($function, SymbolKind::Function);
		if ($declaration instanceof FunctionNode) {
			$list = $declaration->parent;
			if (!$list instanceof PlainNodeList || (!$list->parent instanceof FileNode && !$list->parent instanceof NamespaceNode)) {
				return null;
			}

			$counting = $declaration->find(
				FunctionCallNode::class,
				fn(FunctionCallNode $inner) => GlobalCalls::findFunction($inner, self::CountingFunctions, $context) !== null,
			);
			$defaults = [];
			foreach ($declaration->parameters->getItems() as $parameter) {
				$defaults[(string) $parameter->variable->plainName] = self::readDefault($parameter);
			}
			return $counting === [] ? [$declaration->name->text, $defaults, null] : null;
		}

		$parameters = $resolver->isGlobalFunctionCall($call)
			? $context->getAnalysis(PhpSignatures::class)->findParameters($function)
			: null;
		if ($parameters === null) {
			return null;
		}

		$function = ltrim($function, '\\');
		$defaults = [];
		foreach ($parameters as $parameter) {
			$required = isset(CsvEscapeArgumentRequiredRule::Functions[strtolower($function)]) && $parameter->name === 'escape';
			$defaults[$parameter->name] = $parameter->variadic || $required ? null : $parameter->default;
		}
		return [$function, $defaults, GlobalCalls::findUncertainty($call, $context)];
	}


	/** The default of a parameter declared in the file as PHP code, written the way the catalog writes one. */
	private static function readDefault(ParameterNode $parameter): ?string
	{
		return $parameter->ellipsis === null && $parameter->default?->hasValue()
			? self::writeValue($parameter->default->toValue())
			: null;
	}


	/** Whether the argument is passed plainly and written as the very value the default is. */
	private static function repeatsDefault(ArgumentNode $argument, ?string $default): bool
	{
		return $default !== null
			&& $argument->ampersand === null
			&& $argument->ellipsis === null
			&& $argument->value->hasValue()
			&& self::writeValue($argument->value->toValue()) === $default;
	}


	/** The value as PHP code, the way the catalog of PHP and the types write a default. */
	private static function writeValue(mixed $value): string
	{
		return match (true) {
			$value === null => 'null',
			is_bool($value) => $value ? 'true' : 'false',
			$value === [] => '[]',
			default => var_export($value, true),
		};
	}
}
