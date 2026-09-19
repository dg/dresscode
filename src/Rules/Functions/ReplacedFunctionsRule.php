<?php declare(strict_types=1);

namespace DressCode\Rules\Functions;

use DressCode\Analyses\Parameter;
use DressCode\Analyses\PhpSignatures;
use DressCode\Analyses\PhpSymbols;
use DressCode\ConfigurableRule;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use Nette\Schema\Context;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\VariadicPlaceholderNode;
use PhpSyntax\Token;
use function array_find, count;


/**
 * A tool for replacing calls across a codebase: the project maps a function to the function it wants written
 * instead, and the rule rewrites every call. The fix is not risky, because what changes is exactly what the
 * project asked for; only a call in a namespace that may reach a function of that namespace instead of the global
 * one is, since there the code, not the map, decides which function it calls.
 *
 * A call is rewritten only where the replacement takes every argument of it: the parameter standing at the position
 * of a positional argument takes what the replaced one took, a named argument names a parameter of the replacement
 * too, and no parameter the replacement needs is left without one. That is what tells `strpos` from `substr_count`,
 * whose third parameter is the encoding where the replaced one has the offset. Where either function is not PHP's
 * own, its parameters are unknown and the map is taken at its word, since it is the project that wrote it.
 *
 * The replaced function is a global one, which is what a call reaches through the fallback of a namespace; the
 * replacement is written the way the scope writes a global function, or fully qualified when it has a namespace
 * of its own, the rules of the notation of names then writing it as the project spells such a name.
 */
#[RuleInfo(
	'dresscode/replaced-functions',
	Stage::Structure,
	description: 'Calls the function a project or its libraries write instead of another one',
	group: Group::Deprecations,
)]
final class ReplacedFunctionsRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, array{string, string}>  lowercased replaced name => the replaced and the replacing name as the project spells them */
	private array $functions = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::arrayOf(
			Expect::string()->pattern('\\\\?\w+(\\\\\w+)*'),
			Expect::string()->pattern('\\\\?\w+'),
		)
			->description('The global function → the function written instead, which is written fully qualified when it has a namespace of its own')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $old => $new) {
					if (strcasecmp(ltrim((string) $old, '\\'), ltrim($new, '\\')) === 0) {
						$context->addError("The function $old() is given as its own replacement.", 'dresscode.sameFunction');
					}
				}

				return $options;
			});
	}


	public function configure(array $options): void
	{
		$this->functions = [];
		foreach ($options as $old => $new) {
			// both as the project spells them, which is what its message quotes back at it
			$this->functions[strtolower(ltrim((string) $old, '\\'))] = [ltrim((string) $old, '\\'), ltrim($new, '\\')];
		}
	}


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| !$resolver->isGlobalFunctionCall($node)
		) {
			return;
		}

		// the function the name reaches, which an import may call by another name
		$replacement = $this->functions[strtolower($resolver->resolveFunction($node->name))] ?? null;
		if ($replacement === null) {
			return;
		}

		[$old, $new] = $replacement;

		$refusal = $this->findRefusal($old, $new, $node, $context);
		$uncertainty = $refusal === null ? NodeHelpers::findUncertainty($node, $context) : null;
		if (!$context->report(
			$node->name,
			"Function $old() is replaced by $new()" . ($refusal ?? $uncertainty ?? ''),
			fixable: $refusal === null,
			risky: $uncertainty !== null,
		)) {
			return;
		}

		$node->name->text = str_contains($new, '\\')
			? '\\' . $new
			: NodeHelpers::spellGlobalFunction($new, $node->name, $context);
	}


	/** Why the call cannot be rewritten, as a clause of the message; null when it can. */
	private function findRefusal(string $old, string $new, FunctionCallNode $call, RuleContext $context): ?string
	{
		$version = $context->getPhpVersion();
		$symbols = $context->getAnalysis(PhpSymbols::class);
		if ($symbols->isInternalFunction($new) && !$symbols->isInternalFunction($new, $version)) {
			return ", but PHP $version has no $new()";
		}

		$signatures = $context->getAnalysis(PhpSignatures::class);
		$from = $signatures->findParameters($old);
		$to = $signatures->findParameters($new);
		if ($from === null || $to === null) {
			return null;
		}

		$positions = 0;
		$named = [];
		foreach ($call->arguments->items as $argument) {
			if ($argument instanceof VariadicPlaceholderNode) {
				// the ... makes a closure of the call, which stands for every argument the replaced function takes
				$positions = count($from);
				$refusal = self::compareUpTo($from, $to, $positions, $old, $new);
			} elseif ($argument instanceof ArgumentNode && $argument->ellipsis !== null) {
				return ', but what the unpacked argument holds is unknown';
			} elseif ($argument instanceof ArgumentNode && $argument->name !== null) {
				$named[] = $argument->name->text;
				$refusal = self::compare(
					self::findByName($from, $argument->name->text),
					self::findByName($to, $argument->name->text),
					'parameter $' . $argument->name->text,
					$old,
					$new,
				);
			} else {
				// a positional argument, or a ? placeholder holding its position without giving it a value
				$refusal = self::compare(
					self::findAt($from, $positions),
					self::findAt($to, $positions),
					'argument #' . ++$positions,
					$old,
					$new,
				);
			}

			if ($refusal !== null) {
				return $refusal;
			}
		}

		foreach ($to as $position => $parameter) {
			if (!$parameter->optional && $position >= $positions && !in_array($parameter->name, $named, true)) {
				return ", but $new() needs \$$parameter->name";
			}
		}

		return null;
	}


	/**
	 * Whether the replacement takes what the replaced function took at every one of the first positions, which is
	 * what a call giving no value at a position asks.
	 * @param  list<Parameter>  $from
	 * @param  list<Parameter>  $to
	 */
	private static function compareUpTo(array $from, array $to, int $positions, string $old, string $new): ?string
	{
		for ($position = 0; $position < $positions; $position++) {
			$refusal = self::compare(
				self::findAt($from, $position),
				self::findAt($to, $position),
				'argument #' . ($position + 1),
				$old,
				$new,
			);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}


	/** Whether the argument written for one parameter may stand at the other, as a clause of the message. */
	private static function compare(?Parameter $from, ?Parameter $to, string $which, string $old, string $new): ?string
	{
		return match (true) {
			$from === null => ", but $old() has no $which",
			$to === null => ", but $new() has no $which",
			$to->canReplace($from) => null,
			default => ", but the $which of $new() takes $to->type where $old() takes $from->type",
		};
	}


	/** @param  list<Parameter>  $parameters */
	private static function findAt(array $parameters, int $position): ?Parameter
	{
		$last = $parameters[count($parameters) - 1] ?? null;
		return $parameters[$position] ?? ($last?->variadic === true ? $last : null);
	}


	/** @param  list<Parameter>  $parameters */
	private static function findByName(array $parameters, string $name): ?Parameter
	{
		return array_find($parameters, fn(Parameter $parameter) => $parameter->name === $name);
	}
}
