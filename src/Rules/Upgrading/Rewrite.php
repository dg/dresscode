<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{CodeWriter, Risk, RuleContext};
use DressCode\Config\Layer;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, ExpressionNode, NameNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, FunctionCallNode, MethodCallNode, NewNode, PropertyFetchNode, StaticMethodCallNode};
use function count;


/**
 * What a template makes of one call: the expression written instead, or why there is none, and why writing it may
 * change what the code does; it reports the use and writes the expression in its place.
 * @internal
 */
final readonly class Rewrite
{
	public function __construct(
		/** detached, null where the call is refused */
		public ?ExpressionNode $expression,
		/** a clause of the message, `, but ...` */
		public ?string $refusal = null,
		/** what may go wrong where the fix is risky, the reason of the report */
		public ?string $because = null,
		/** @var list<NameNode>  the classes the template names fully qualified, to be spelled the way the code reaches them */
		public array $classes = [],
		/** @var list<NameNode>  the functions the template calls fully qualified, a global one to be spelled as the code reaches it */
		public array $functions = [],
	) {
	}


	/** The rewrite risky for the reason, unless it is risky for another one already. */
	public function withRisk(string $because): self
	{
		return new self($this->expression, $this->refusal, $this->because ?? $because, $this->classes, $this->functions);
	}


	/**
	 * Reports the use under the decision, with the section of the upgrading data the entry came with, and with what
	 * the template made of it, and says whether it is to be rewritten.
	 * @phpstan-assert-if-true !null $this->expression
	 */
	public function report(Node|Token $at, string $message, string $decision, ?Layer $source, RuleContext $context): bool
	{
		return $context->report(
			$at,
			$message . $this->refusal . '.',
			decision: $decision,
			source: $source,
			fixable: $this->expression !== null,
			risk: $this->because === null ? null : Risk::BehaviorChanges,
			because: $this->because,
		) && $this->expression !== null;
	}


	/** Whether the rewrite writes the node as it stands, the classes of the template spelled the way the code there reaches them. */
	public function isWrittenAlready(Node $node, RuleContext $context): bool
	{
		if ($this->expression === null) {
			return false;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$spelled = [];
		foreach ($this->classes as $class) {
			$spelled[spl_object_id($class->token)] = $resolver->shortenName(ltrim($class->text, '\\'), SymbolKind::ClassLike, $node);
		}

		$read = fn(Node $node) => array_map(fn(Token $token) => $spelled[spl_object_id($token)] ?? $token->text, $node->getTokens());
		return self::readCall($this->expression, $read) === self::readCall($node, $read);
	}


	/**
	 * The tokens of an expression; of a call, the tokens in front of its arguments and the arguments as
	 * `readArguments()` reads them.
	 * @param  \Closure(Node): list<string>  $read
	 * @return list<mixed>
	 */
	private static function readCall(Node $node, \Closure $read): array
	{
		$arguments = $node instanceof MethodCallNode || $node instanceof StaticMethodCallNode || $node instanceof NewNode
			? $node->arguments
			: null;
		if ($arguments === null) {
			return $read($node);
		}

		$tokens = $read($node);
		return [array_slice($tokens, 0, count($tokens) - count($arguments->getTokens())), self::readArguments($arguments, $read)];
	}


	/**
	 * The arguments as PHP binds them: the positional ones in their order, the named ones by their names, whatever order
	 * they are written in.
	 * @param  \Closure(Node): list<string>  $read
	 * @return array{list<list<string>>, array<string, list<string>>}
	 */
	public static function readArguments(?ArgumentListNode $arguments, \Closure $read): array
	{
		$positional = $named = [];
		foreach ($arguments?->items->getItems() ?? [] as $argument) {
			if ($argument instanceof ArgumentNode && $argument->name !== null) {
				$named[$argument->name->text] = $read($argument);
			} else {
				$positional[] = $read($argument);
			}
		}

		ksort($named);
		return [$positional, $named];
	}


	/**
	 * The expression written in place of the node, the classes and the global functions of the template spelled the way
	 * the code there reaches them, a function bare only in place of a call of a bare name.
	 */
	public function write(Node $node, RuleContext $context): ExpressionNode
	{
		assert($this->expression !== null);
		foreach ($this->classes as $class) {
			$class->text = CodeWriter::writeClass(ltrim($class->text, '\\'), $node, $context);
		}

		$replaced = $node instanceof FunctionCallNode ? $node->name : $node;
		foreach ($this->functions as $function) {
			$name = ltrim($function->text, '\\');
			if (!str_contains($name, '\\') && ($replaced instanceof NameNode || $replaced instanceof ExpressionNode)) {
				$function->text = CodeWriter::spellFunction($name, $replaced, $context);
			}
		}

		// an access on what the replaced call was made on keeps a chain broken before its operator
		$link = $this->expression;
		while ($node instanceof MethodCallNode && $link !== null) {
			if (($link instanceof MethodCallNode || $link instanceof PropertyFetchNode) && $link->object->matches($node->object)) {
				$link->object->getLastToken()->setTrailingTrivia($node->object->getLastToken()->trailingTrivia);
				$link->operator->setLeadingTrivia($node->operator->leadingTrivia);
				break;
			}

			$link = match (true) {
				$link instanceof ArrayAccessNode => $link->expression,
				$link instanceof MethodCallNode, $link instanceof PropertyFetchNode => $link->object,
				default => null,
			};
		}

		return $this->expression;
	}
}
