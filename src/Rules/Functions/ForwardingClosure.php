<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use PhpSyntax\{DereferenceKind, Node};
use PhpSyntax\Nodes\{ArgumentNode, IdentifierNode, NameNode, ParameterNode};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode, FunctionCallNode, MethodCallNode, StaticMethodCallNode, VariableNode};
use PhpSyntax\Nodes\Statement\ReturnNode;
use function count, in_array;


/**
 * A closure or an arrow function that does nothing but return one call, read the way the rules writing it as a
 * callable read it.
 * @internal
 */
final class ForwardingClosure
{
	/**
	 * The call the function does nothing but return; null where it does anything else, returns by reference,
	 * declares its return type, imports variables or carries an attribute, all of which a callable cannot say.
	 */
	public static function findCall(ClosureNode|ArrowFunctionNode $function): FunctionCallNode|MethodCallNode|StaticMethodCallNode|null
	{
		if (
			!$function->attributes->isEmpty()
			|| $function->ampersand !== null
			|| $function->returnType !== null
			|| ($function instanceof ClosureNode && $function->uses !== null)
		) {
			return null;
		}

		$statements = $function instanceof ClosureNode ? $function->body->statements->getItems() : [];
		$body = match (true) {
			$function instanceof ArrowFunctionNode => $function->expression,
			count($statements) === 1 && $statements[0] instanceof ReturnNode => $statements[0]->expression,
			default => null,
		};
		return $body instanceof FunctionCallNode || $body instanceof MethodCallNode || $body instanceof StaticMethodCallNode
			? $body
			: null;
	}


	/**
	 * What the call calls, written the way a callable names it; null for a name computed at run time, for a method
	 * in a nullsafe chain, where PHP refuses a callable, and for `self`, `parent` and `static`, which a callable
	 * resolves where it is made, not where it is called, `parent` in a class without one being an error the closure
	 * would only ever have raised when called.
	 */
	public static function writeCallee(FunctionCallNode|MethodCallNode|StaticMethodCallNode $call): ?string
	{
		return match (true) {
			$call instanceof FunctionCallNode => $call->name instanceof NameNode ? $call->name->text : null,
			$call instanceof MethodCallNode => $call->name instanceof IdentifierNode
				&& $call->object->isDereferenceable(DereferenceKind::Fetch)
				&& !$call->isInNullsafeChain()
					? $call->object->text . $call->operator->text . $call->name->text
					: null,
			default => $call->name instanceof IdentifierNode
				&& $call->class instanceof NameNode
				&& !self::isScopeRelative($call->class->text)
					? $call->class->text . '::' . $call->name->text
					: null,
		};
	}


	/**
	 * Whether the argument passes the parameter on untouched: as itself, by position, spread exactly where the
	 * parameter is variadic, and the parameter declaring nothing a callable would not say, no type, no default, no
	 * reference.
	 */
	public static function passesParameter(ParameterNode $parameter, Node $argument): bool
	{
		return $parameter->ampersand === null
			&& $parameter->default === null
			&& $parameter->type === null
			&& $argument instanceof ArgumentNode
			&& $argument->name === null
			&& $argument->ampersand === null
			&& ($argument->ellipsis === null) === ($parameter->ellipsis === null)
			&& $argument->value instanceof VariableNode
			&& $argument->value->plainName === $parameter->variable->plainName;
	}


	/** Whether the name is one the scope gives its meaning to, which a callable resolves at once. */
	public static function isScopeRelative(string $name): bool
	{
		return in_array(strtolower(ltrim($name, '\\')), ['self', 'parent', 'static'], true);
	}
}
