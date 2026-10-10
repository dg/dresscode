<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\{OverriddenSignature, Parameter, Types};
use DressCode\{Decision, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\Words;
use DressCode\Rules\{CodeWriter, NativeType, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token, Trivia, Visibility};
use PhpSyntax\Nodes\{AnonymousFunctionNode, ParameterNode};
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{FunctionNode, TraitNode};
use function count;


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
 * written otherwise, and where the name is taken; the names are the decision `classes.overriding.parameterName`, the
 * rest `classes.overriding.signature`. A type PHP cannot write as it describes it, a generic or a static of a class,
 * and a default that is no value to write are reported and left.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	typesRequired: true,
	analyses: [Types::class, NameResolver::class],
)]
final class OverridingSignatureRule extends NodeRule
{
	private const Signature = 'classes.overriding.signature';
	private const ParameterNames = 'classes.overriding.parameterName';

	private bool $fixesSignature = true;

	private bool $parameterNames = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Signature, new Words(['asAncestor' => 'as the ancestor declares them']), 'The return type, the types of the parameters, the parameters added with a default, the visibility and `static` of a method overriding one of a parent class or an interface'),
			new Decision(self::ParameterNames, new Words(['asAncestor' => 'as the ancestor names them']), 'The names of the parameters of a method overriding one of a parent class or an interface'),
		];
	}


	public function configure(Values $values): void
	{
		$this->fixesSignature = !$values->isKept(self::Signature);
		$this->parameterNames = !$values->isKept(self::ParameterNames);
	}


	public function getVisitedNodes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| $node->isConstructor()
			|| $node->isDestructor()
			|| $node->findAncestor(TraitNode::class) !== null
		) {
			return;
		}

		$signature = $context->getAnalysis(Types::class)->findOverriddenSignature($node);
		if ($signature === null) {
			return;
		} elseif ($signature->final) {
			if ($this->fixesSignature) {
				$context->report($node->name, "The method `{$node->name->text}()` overrides the final `$signature->declaringClass::{$node->name->text}()`.", fixable: false, decision: self::Signature);
			}

			return;
		}

		if ($this->fixesSignature) {
			if ($signature->static !== $node->modifiers->static) {
				$this->fixStatic($node, $signature, $context);
			}

			$this->widenVisibility($node, $signature, $context);
			$this->fixReturnType($node, $signature, $context);
		}

		$this->fixParameters($node, $signature, $context);
	}


	/**
	 * A method the ancestor made static is made static too where its body needs no object; one the ancestor declares
	 * without `static` is only reported, a caller may call the static one through the class.
	 */
	private function fixStatic(MethodNode $node, OverriddenSignature $signature, RuleContext $context): void
	{
		$name = $node->name->text;
		if (!$signature->static) {
			$context->report($node->name, "The method `$name()` must not be static, as in `$signature->declaringClass::$name()`.", fixable: false, decision: self::Signature);
			return;
		}

		$message = "The method `$name()` must be static, as in `$signature->declaringClass::$name()`";
		if (NodeHelpers::needsObject($node, $context)) {
			$context->report($node->name, $message . ', but its body uses the object.', fixable: false, decision: self::Signature);
		} elseif ($context->report($node->name, $message . '.', decision: self::Signature)) {
			$node->modifiers->append(Token::fromText('static'));
		}
	}


	private function widenVisibility(MethodNode $node, OverriddenSignature $signature, RuleContext $context): void
	{
		$own = $node->modifiers->visibility;
		$word = strtolower($signature->visibility->name);
		$token = $node->modifiers->getVisibilityToken();
		if (
			$own !== $signature->visibility
			&& ($own === Visibility::Private || $signature->visibility === Visibility::Public)
			&& $token !== null
			&& $context->report($token, "The method `{$node->name->text}()` must be $word, as in `$signature->declaringClass::{$node->name->text}()`.", decision: self::Signature)
		) {
			$token->replaceWith(Token::fromText($word));
		}
	}


	private function fixReturnType(MethodNode $node, OverriddenSignature $signature, RuleContext $context): void
	{
		if (!$signature->returnWidened || $signature->returnType === null) {
			return;
		}

		$writable = self::canWriteType($signature->returnType);
		$message = "The method `{$node->name->text}()` must declare the return type " . Violation::formatCode($signature->returnType)
			. ", as in `$signature->declaringClass::{$node->name->text}()`";
		if (!$context->report(
			$node->returnType ?? $node->name,
			$message . ($writable ? '' : ', but that type cannot be written') . '.',
			fixable: $writable,
			decision: self::Signature,
			risk: $writable ? Risk::TypeUnknown : null,
			because: $writable ? 'the body may return a value of another type' : null,
		)) {
			return;
		}

		$node->setReturnType((new Builder)->type((string) self::writeType($signature->returnType, $node, $context)));
	}


	private function fixParameters(MethodNode $node, OverriddenSignature $signature, RuleContext $context): void
	{
		$own = $node->parameters->getItems();
		foreach ($signature->parameters as $i => $parameter) {
			$mine = $own[$i] ?? null;
			if ($mine !== null) {
				$this->fixParameter($node, $mine, $parameter, in_array($i, $signature->narrowedParameters, true), $signature, $context);
			} elseif (array_any($own, fn(ParameterNode $item) => $item->ellipsis !== null)) {
				return; // a variadic parameter takes whatever follows
			} elseif ($this->fixesSignature) {
				self::addParameter($node, $parameter, $signature, $context);
			}
		}
	}


	private function fixParameter(
		MethodNode $node,
		ParameterNode $mine,
		Parameter $parameter,
		bool $narrowed,
		OverriddenSignature $signature,
		RuleContext $context,
	): void
	{
		$method = "$signature->declaringClass::{$node->name->text}()";
		$name = (string) $mine->variable->plainName;
		if ($narrowed && $this->fixesSignature) {
			$writable = $parameter->type === null || self::canWriteType($parameter->type);
			$message = "The parameter `\$$name` of `{$node->name->text}()` must take "
				. ($parameter->type === null ? 'any value' : Violation::formatCode($parameter->type)) . ", as in `$method`";
			if ($context->report($mine->type ?? $mine, $message . ($writable ? '' : ', but that type cannot be written') . '.', fixable: $writable, decision: self::Signature)) {
				$mine->setType($parameter->type === null ? null : (new Builder)->type((string) self::writeType($parameter->type, $node, $context)));
			}
		}

		if (!$this->parameterNames || $name === $parameter->name) {
			return;
		}

		$refusal = self::findRenameRefusal($node, $parameter->name, $context);
		if ($context->report(
			$mine->variable,
			"The parameter `\$$name` of `{$node->name->text}()` must be named `\$$parameter->name`, as in `$method`" . ($refusal === null ? '' : ", but $refusal") . '.',
			fixable: $refusal === null,
			decision: self::ParameterNames,
			risk: $refusal === null ? Risk::BehaviorChanges : null,
			because: $refusal === null ? "a call naming the argument `$name:` stops working" : null,
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
			$body->find(AnonymousFunctionNode::class) !== [] => 'a closure in the body may use it by its name',
			$body->find(FunctionNode::class) !== [] => 'a function declared in the body has variables of its own',
			NodeHelpers::findDynamicVariableAccesses($body, $context) !== [] => 'the body names a variable indirectly',
			array_any($node->find(VariableNode::class), fn(VariableNode $variable) => $variable->plainName === $name) => "`\$$name` is taken in the method",
			default => null,
		};
	}


	private static function renameParameter(MethodNode $node, string $old, string $new): void
	{
		foreach ($node->find(VariableNode::class) as $variable) {
			if ($variable->plainName === $old && $variable->name instanceof Token) {
				$variable->name->setText(($variable->name->text[0] === '$' ? '$' : '') . $new);
			}
		}

		$first = $node->getFirstToken();
		foreach ($first->leadingTrivia as $trivia) {
			if (
				$trivia->is(Trivia::DocComment)
				&& preg_match('~\$' . preg_quote($old, '~') . '\b~', $trivia->text)
			) {
				$first->replaceTrivia($trivia, $trivia->withText((string) preg_replace('~\$' . preg_quote($old, '~') . '\b~', '$' . $new, $trivia->text)));
			}
		}
	}


	/** Adds the parameter the ancestor declares after the last one of the declaration, where it is optional and its default a value to write. */
	private static function addParameter(MethodNode $node, Parameter $parameter, OverriddenSignature $signature, RuleContext $context): void
	{
		$writable = ($parameter->type === null || self::canWriteType($parameter->type))
			&& ($parameter->variadic || ($parameter->optional && $parameter->default !== null));
		$message = "The method `{$node->name->text}()` must declare the parameter `\$$parameter->name`, as in `$signature->declaringClass::{$node->name->text}()`";
		$reason = match (true) {
			$writable => '',
			!$parameter->optional && !$parameter->variadic => ', but the parameter is required',
			$parameter->type !== null && !self::canWriteType($parameter->type) => ", but its type `$parameter->type` cannot be written",
			default => ', but its default cannot be written',
		};
		if (!$context->report($node->name, $message . $reason . '.', fixable: $writable, decision: self::Signature)) {
			return;
		}

		$code = ($parameter->type === null ? '' : self::writeType($parameter->type, $node, $context) . ' ')
			. ($parameter->byReference ? '&' : '')
			. ($parameter->variadic ? '...' : '')
			. '$' . $parameter->name
			. ($parameter->variadic ? '' : ' = ' . $parameter->default);
		$new = (new Builder)->fragment(ParameterNode::class, $code);
		$node->parameters->insert(count($node->parameters->getItems()), $new);
	}


	/**
	 * Whether the type is one PHP writes as it is described: no generic, no static of a class, parentheses only around
	 * an intersection in a union.
	 */
	private static function canWriteType(string $type): bool
	{
		return !preg_match('~[<>(){}\[\]\s]~', (string) preg_replace('~\(([\w\\\\]+(?:&[\w\\\\]+)+)\)~', '$1', $type));
	}


	/**
	 * The type as code, its classes spelled the way the file writes them, an intersection in a union in parentheses, a
	 * union of one type with null written with ?.
	 */
	private static function writeType(string $type, Node $at, RuleContext $context): string
	{
		$members = [];
		foreach (explode('|', $type) as $member) {
			$members[] = implode('&', array_map(
				fn(string $name) => in_array(strtolower($name), NativeType::Builtin, true)
					? strtolower($name)
					: CodeWriter::writeClass($name, $at, $context),
				explode('&', trim($member, '()')),
			));
		}

		if (count($members) > 1) {
			$members = array_map(fn(string $member) => str_contains($member, '&') ? "($member)" : $member, $members);
		}

		$others = array_values(array_diff($members, ['null']));
		return count($members) === 2 && count($others) === 1 && !str_contains($others[0], '&') && $others[0] !== 'mixed'
			? '?' . $others[0]
			: implode('|', $members);
	}
}
