<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\{Signature, SignatureParameter, Types};
use DressCode\{ConfigurableRule, Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\{CodeWriter, NodeHelpers};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Node, Parser, Token, TokenKind, Trivia, TriviaKind};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClosureNode, StaticMethodCallNode, VariableNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\{NameNode, ParameterNode, TypeNode};
use PhpSyntax\Nodes\Statement\{FunctionNode, TraitNode};
use function count, in_array, ord;


/**
 * A method overriding one of a parent class or an interface is declared the way the ancestor declares it in the
 * version installed, which is what an upgrade of a library breaks in a child: the return type the ancestor declares
 * where the child declares none or a wider one, the type of a parameter the child narrows, a parameter the ancestor
 * added with a default, the name of a parameter, a visibility the child narrows, `static` the ancestor added where
 * the body of the child needs no object. A final method of the ancestor is reported, nothing could make the child
 * override it, and so is a static child of a method the ancestor declares without `static`, or one whose body needs
 * the object. A constructor, a destructor and a method of a trait are left
 * alone.
 *
 * Writing the return type is risky, the body may return something else; renaming a parameter is risky, a caller
 * passing it by name notices, and it is refused where the body has a closure or reaches a variable by its name
 * written otherwise, and where the name is taken; the option `parameterNames` turns the names off. A type PHP
 * cannot write as it describes it, a generic or a static of a class, and a default that is no value to write are
 * reported and left.
 */
#[RuleInfo(
	'dresscode/override-signature',
	Stage::Structure,
	description: 'Declares an overriding method the way the ancestor declares it: its types, its parameters and their names',
	group: Group::Deprecations,
	modifiesComments: true,
	requiresTypes: true,
	decision: 'parameterNames',
)]
final class OverrideSignatureRule extends NodeRule implements ConfigurableRule
{
	private const BuiltinTypes = [
		'int', 'float', 'string', 'bool', 'array', 'iterable', 'callable', 'object', 'mixed', 'void', 'never', 'null', 'false', 'true',
		'static', 'self', 'parent',
	];

	private bool $parameterNames = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'parameterNames' => Expect::bool(true)->description('Whether a parameter takes the name the ancestor gives it'),
		])->castTo('array');
	}


	public function configure(array $options): void
	{
		$this->parameterNames = $options['parameterNames'];
	}


	public function getVisitedTypes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| in_array(strtolower($node->name->text), ['__construct', '__destruct'], true)
			|| $node->findAncestor(TraitNode::class) !== null
		) {
			return;
		}

		$signature = $context->getAnalysis(Types::class)->findOverriddenSignature($node);
		if ($signature === null) {
			return;
		} elseif ($signature->final) {
			$context->report($node->name, "Method {$node->name->text}() overrides the final $signature->class::{$node->name->text}()", fixable: false);
			return;
		} elseif ($signature->static !== $node->modifiers->isStatic()) {
			$this->fixStatic($node, $signature, $context);
			return;
		}

		$this->widenVisibility($node, $signature, $context);
		$this->fixReturnType($node, $signature, $context);
		$this->fixParameters($node, $signature, $context);
	}


	/**
	 * A method the ancestor made static is made static too where its body needs no object; one the ancestor declares
	 * without `static` is only reported, a caller may call the static one through the class.
	 */
	private function fixStatic(MethodNode $node, Signature $signature, RuleContext $context): void
	{
		$name = $node->name->text;
		if (!$signature->static) {
			$context->report($node->name, "Method $name() is static while $signature->class::$name() is not", fixable: false);
			return;
		}

		$needsObject = $node->body === null
			|| NodeHelpers::findDynamicVariableAccesses($node->body, $context) !== []
			|| array_any(
				$node->body->find(Node::class),
				fn(Node $inner) => ($inner instanceof VariableNode && $inner->isThis())
					|| ($inner instanceof StaticMethodCallNode && $inner->class instanceof NameNode && strtolower($inner->class->text) === 'parent'),
			);
		$message = "Method $name() is not static while $signature->class::$name() is";
		if ($needsObject) {
			$context->report($node->name, $message . ', and its body uses the object', fixable: false);
		} elseif ($context->report($node->name, $message)) {
			$token = new Token(TokenKind::Static, 'static');
			$token->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
			$node->modifiers->append($token);
		}
	}


	private function widenVisibility(MethodNode $node, Signature $signature, RuleContext $context): void
	{
		$own = match (true) {
			$node->modifiers->isPrivate() => 'private',
			$node->modifiers->isProtected() => 'protected',
			default => 'public',
		};
		$token = array_find($node->modifiers->getTokens(), fn(Token $token) => strtolower($token->text) === $own);
		if (
			$own !== $signature->visibility
			&& ($own === 'private' || $signature->visibility === 'public')
			&& $token !== null
			&& $context->report($token, "Method {$node->name->text}() is $own while $signature->class::{$node->name->text}() is $signature->visibility")
		) {
			$token->setText($signature->visibility);
		}
	}


	private function fixReturnType(MethodNode $node, Signature $signature, RuleContext $context): void
	{
		if (!$signature->returnWidened || $signature->returnType === null) {
			return;
		}

		$writable = self::canWriteType($signature->returnType);
		$message = "Method {$node->name->text}() " . ($node->returnType === null ? 'declares no return type' : "returns {$node->returnType->text}")
			. " while $signature->class::{$node->name->text}() returns $signature->returnType";
		if (!$context->report(
			$node->returnType ?? $node->name,
			$message . ($writable ? ', which the body may not return' : ', but that type cannot be written'),
			fixable: $writable,
			risky: $writable,
		)) {
			return;
		}

		$type = self::parseType((string) self::writeType($signature->returnType, $node, $context));
		if ($node->returnType !== null) {
			$node->returnType->replaceWith($type);
			return;
		}

		// the gap behind the parenthesis moves behind the type
		$trailing = $node->closeParen->trailingTrivia;
		$node->closeParen->setTrailingTrivia([]);
		$colon = new Token(ord(':'), ':');
		$colon->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		$node->colon = $colon;
		$node->returnType = $type;
		$type->getLastToken()?->setTrailingTrivia($trailing);
	}


	private function fixParameters(MethodNode $node, Signature $signature, RuleContext $context): void
	{
		$own = $node->parameters->getItems();
		foreach ($signature->parameters as $i => $parameter) {
			$mine = $own[$i] ?? null;
			if ($mine !== null) {
				$this->fixParameter($node, $mine, $parameter, $signature, $context);
			} elseif (array_any($own, fn(ParameterNode $item) => $item->ellipsis !== null)) {
				return; // a variadic parameter takes whatever follows
			} else {
				self::addParameter($node, $parameter, $signature, $context);
			}
		}
	}


	private function fixParameter(
		MethodNode $node,
		ParameterNode $mine,
		SignatureParameter $parameter,
		Signature $signature,
		RuleContext $context,
	): void
	{
		$method = "$signature->class::{$node->name->text}()";
		$name = (string) $mine->variable->plainName;
		if ($parameter->narrowed) {
			$writable = $parameter->type === null || self::canWriteType($parameter->type);
			$message = "Parameter \$$name of {$node->name->text}() takes " . ($mine->type->text ?? 'anything')
				. ' while that of ' . $method . ' takes ' . ($parameter->type ?? 'anything');
			if ($context->report($mine->type ?? $mine, $message . ($writable ? '' : ', but that type cannot be written'), fixable: $writable)) {
				$parameter->type === null
					? $mine->type = null
					: $mine->type?->replaceWith(self::parseType((string) self::writeType($parameter->type, $node, $context)));
			}
		}

		if (!$this->parameterNames || $name === $parameter->name) {
			return;
		}

		$refusal = self::findRenameRefusal($node, $parameter->name, $context);
		if ($context->report(
			$mine->variable,
			"Parameter \$$name of {$node->name->text}() is named \$$parameter->name in $method" . ($refusal ?? ', which a caller passing it by name notices'),
			fixable: $refusal === null,
			risky: true,
		)) {
			self::renameParameter($node, $name, $parameter->name);
		}
	}


	/** Why the parameter cannot take the name: the body reaches a variable in a way a rename does not follow, or the name is taken. */
	private static function findRenameRefusal(MethodNode $node, string $name, RuleContext $context): ?string
	{
		$body = $node->body;
		return match (true) {
			$body === null => null,
			$body->find(ClosureNode::class) !== [] || $body->find(ArrowFunctionNode::class) !== [] || $body->find(FunctionNode::class) !== []
				=> ', but a function in the body may see the variable by its name',
			NodeHelpers::findDynamicVariableAccesses($body, $context) !== [] => ', but the body names a variable indirectly',
			array_any($node->find(VariableNode::class), fn(VariableNode $variable) => $variable->plainName === $name) => ", but \$$name is taken in the method",
			default => null,
		};
	}


	private static function renameParameter(MethodNode $node, string $old, string $new): void
	{
		foreach ($node->find(VariableNode::class) as $variable) {
			if ($variable->plainName === $old && $variable->name instanceof Token) {
				$variable->name->setText('$' . $new);
			}
		}

		$first = $node->getFirstToken();
		foreach ($first->leadingTrivia ?? [] as $trivia) {
			if (
				$first !== null
				&& $trivia->kind === TriviaKind::DocComment
				&& preg_match('~\$' . preg_quote($old, '~') . '\b~', $trivia->text)
			) {
				$first->replaceTrivia($trivia, new Trivia($trivia->kind, (string) preg_replace('~\$' . preg_quote($old, '~') . '\b~', '$' . $new, $trivia->text), $trivia->inInterpolation, $trivia->originalLine));
			}
		}
	}


	/** Adds the parameter the ancestor declares after the last one of the declaration, where it is optional and its default a value to write. */
	private static function addParameter(MethodNode $node, SignatureParameter $parameter, Signature $signature, RuleContext $context): void
	{
		$writable = ($parameter->type === null || self::canWriteType($parameter->type))
			&& ($parameter->variadic || ($parameter->optional && $parameter->default !== null));
		$message = "Method {$node->name->text}() lacks the parameter \$$parameter->name that $signature->class::{$node->name->text}() declares";
		$reason = match (true) {
			$writable => '',
			!$parameter->optional => ', but the parameter is required',
			default => ', but its default cannot be written',
		};
		if (!$context->report($node->name, $message . $reason, fixable: $writable)) {
			return;
		}

		$code = ($parameter->type === null ? '' : self::writeType($parameter->type, $node, $context) . ' ')
			. ($parameter->byReference ? '&' : '')
			. ($parameter->variadic ? '...' : '')
			. '$' . $parameter->name
			. ($parameter->variadic ? '' : ' = ' . $parameter->default);
		$new = (new Parser)->parseFragment(ParameterNode::class, $code);
		$node->parameters->insert(count($node->parameters->getItems()), $new);
	}


	/** Whether the type is one PHP writes as it is described: no generic, no static of a class. */
	private static function canWriteType(string $type): bool
	{
		return !preg_match('~[<>(){}\[\]\s]~', $type);
	}


	/** The type as code, its classes spelled the way the file writes them, a union of one type with null written with ?. */
	private static function writeType(string $type, Node $at, RuleContext $context): string
	{
		$members = [];
		foreach (explode('|', $type) as $member) {
			$members[] = implode('&', array_map(
				fn(string $name) => in_array(strtolower($name), self::BuiltinTypes, true)
					? strtolower($name)
					: CodeWriter::spellClass($name, $at, $context),
				explode('&', $member),
			));
		}

		$others = array_values(array_diff($members, ['null']));
		return count($members) === 2 && count($others) === 1 && !str_contains($others[0], '&') && $others[0] !== 'mixed'
			? '?' . $others[0]
			: implode('|', $members);
	}


	private static function parseType(string $code): TypeNode
	{
		$parameter = (new Parser)->parseFragment(ParameterNode::class, "$code \$x");
		assert($parameter->type !== null);
		return $parameter->type->withoutEdgeTrivia();
	}
}
