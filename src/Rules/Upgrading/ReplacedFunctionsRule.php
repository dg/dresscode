<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Parameter, PhpSignatures, PhpSymbols};
use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\NodeHelpers;
use Nette\Schema\{Context, Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentNode, NameNode, VariadicPlaceholderNode};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use function array_find, count, is_int, strlen;


/**
 * A tool for replacing calls across a codebase: the project, or a library it stands on, maps a function to the
 * function it wants written instead, and the rule rewrites every call. The fix is not risky, because what changes
 * is exactly what the map asked for; only a call in a namespace that may reach a function of that namespace instead of the global
 * one is, since there the code, not the map, decides which function it calls.
 *
 * A call is rewritten only where the replacement takes every argument of it: the parameter standing at the position
 * of a positional argument takes what the replaced one took, a named argument names a parameter of the replacement
 * too, no parameter the replacement needs is left without one, and the target version of PHP has the replacement.
 * That is what keeps `substr_count($s, $n, 3)` from becoming `mb_substr_count()`, whose third parameter is the
 * encoding where the replaced one has the offset. Where either function is not PHP's own, its parameters are
 * unknown and the map is taken at its word, since whoever wrote the map knows it.
 *
 * The replaced function is a global one, which is what a call reaches through the fallback of a namespace, or one of
 * a namespace, `Acme\Template\escape`, which a call reaches through an import, by its qualified name, or unqualified
 * inside that namespace. The replacement is written the way the scope writes a global function, by its short name
 * inside its own namespace or imported where the call reached the replaced one unqualified, by the qualified name the
 * call wrote where both share a namespace, or fully qualified elsewhere, the rules of the notation of names then
 * writing it as the project spells such a name.
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
			Expect::string()->pattern('\\\\?\w+(\\\\\w+)*'),
		)
			->description('The function, global or of a namespace → the function written instead')
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
			if ($new !== MemberMaps::Keep) { // an entry a later layer withdrew
				// both as the project spells them, which is what its message quotes back at it
				$this->functions[strtolower(ltrim((string) $old, '\\'))] = [ltrim((string) $old, '\\'), ltrim($new, '\\')];
			}
		}
	}


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		if (!$node instanceof FunctionCallNode || !$node->name instanceof NameNode || $node->name->isKeyword()) {
			return;
		}

		// the function the name reaches, which an import may call by another name; an unqualified name inside
		// a namespace reaches the function of that namespace where the map names one, the global one otherwise
		$namespace = $resolver->getNamespace($node);
		$local = $namespace !== '' && !str_contains($node->name->text, '\\')
			? $this->functions[strtolower("$namespace\\{$node->name->text}")] ?? null
			: null;
		$replacement = $local ?? $this->functions[strtolower($resolver->resolveFunction($node->name))] ?? null;
		if ($replacement === null) {
			return;
		}

		[$old, $new] = $replacement;

		$refusal = $this->findRefusal($old, $new, $node, $context);
		$uncertainty = $refusal === null && !str_contains($old, '\\') ? NodeHelpers::findUncertainty($node, $context) : null;
		if (!$context->report(
			$node->name,
			"Function $old() is replaced by $new()" . ($refusal ?? $uncertainty ?? ''),
			fixable: $refusal === null,
			risky: $uncertainty !== null,
		)) {
			return;
		}

		$newNamespace = self::extractNamespace($new);
		$short = substr($new, strlen($newNamespace) + 1);
		$written = $node->name->text;
		$qualified = str_contains($written, '\\');
		$node->name->text = match (true) {
			$newNamespace === '' => NodeHelpers::spellGlobalFunction($new, $node->name, $context),
			!$qualified && strcasecmp($newNamespace, $namespace) === 0 => $short,
			// a qualified name of the same namespace keeps the way it reaches it
			$qualified && strcasecmp($newNamespace, self::extractNamespace($old)) === 0 => substr($written, 0, (int) strrpos($written, '\\') + 1) . $short,
			!$qualified && str_contains($old, '\\') && self::importFunction($new, $short, $node->name, $context) => $short,
			default => '\\' . $new,
		};
	}


	/**
	 * Whether the scope imports the function under its short name, as it imported the one a call reached through an
	 * import; the import is added where the name is free.
	 */
	private static function importFunction(string $function, string $short, NameNode $at, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$imported = $resolver->getFunctionImports($at)[strtolower($short)] ?? null;
		if ($imported !== null) {
			return strcasecmp($imported, $function) === 0;
		}

		$scope = NodeHelpers::findImportScope($at);
		if ($scope === null || !NodeHelpers::canAddImport($scope) || !$resolver->isAliasFree($short, SymbolKind::Function, $at)) {
			return false;
		}

		NodeHelpers::addImport($scope, SymbolKind::Function, $function, $context);
		return true;
	}


	/** The namespace of the fully qualified name, '' for a global one. */
	private static function extractNamespace(string $name): string
	{
		return substr($name, 0, max(0, (int) strrpos($name, '\\')));
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
					$argument->name->text,
					$old,
					$new,
				);
			} else {
				// a positional argument, or a ? placeholder holding its position without giving it a value
				$refusal = self::compare(
					self::findAt($from, $positions),
					self::findAt($to, $positions),
					++$positions,
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
				$position + 1,
				$old,
				$new,
			);
			if ($refusal !== null) {
				return $refusal;
			}
		}

		return null;
	}


	/**
	 * Whether the argument written for one parameter may stand at the other, as a clause of the message.
	 * @param  int|string  $at  the position counted from 1, or the name of a named argument
	 */
	private static function compare(?Parameter $from, ?Parameter $to, int|string $at, string $old, string $new): ?string
	{
		$argument = is_int($at) ? "argument #$at" : "parameter \$$at";
		$parameter = is_int($at) ? "parameter #$at" : "parameter \$$at";
		return match (true) {
			$from === null => ", but $old() has no $argument",
			$to === null => ", but $new() has no $argument",
			$to->canReplace($from) => null,
			default => ", but the $parameter of $new() takes $to->type where $old() takes $from->type",
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
