<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\Shapes;
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, DereferenceKind, Node, Nodes, Token};
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\Scalar\MagicConstantNode;
use function count;


/**
 * The `::class` form for the name of the current class or its parent instead of `get_class()`, `get_called_class()`,
 * `get_parent_class()` and `__CLASS__`, and with `cleanup.get_class` also `$object::class` instead of
 * `get_class($object)`. `$this::class` is `static::class`, which says the same without reaching for the object.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class ClassNameNotationRule extends NodeRule
{
	private const Calls = 'cleanup.classNameNotation';
	private const OnObjects = 'cleanup.get_class';

	private bool $calls = true;

	private bool $onObjects = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Calls, new Shapes(['classKeyword' => [
				'self::class',
				'`self::class`, `static::class` and `parent::class`, never `get_class()`, `get_called_class()`, `get_parent_class()` or `__CLASS__`',
			]]), 'The name of the current class, of the one the method runs through and of the parent; the class of an object is `cleanup.get_class`'),
			new Decision(self::OnObjects, Domain::state('forbidden'), 'The class of an object obtained by `get_class($object)`, which is `$object::class`; the name of the current class is `cleanup.classNameNotation`'),
		];
	}


	public function configure(Values $values): void
	{
		$this->calls = !$values->isKept(self::Calls);
		$this->onObjects = !$values->isKept(self::OnObjects);
	}


	public function getVisitedNodes(): array
	{
		return $this->calls
			? [Expression\FunctionCallNode::class, Expression\ClassConstantFetchNode::class, MagicConstantNode::class]
			: [Expression\FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Node) {
			return;
		}

		[$replacement, $decision] = match (true) {
			$node instanceof MagicConstantNode => [strcasecmp($node->token->text, '__CLASS__') === 0 && self::isInClass($node) ? 'self::class' : null, self::Calls],
			$node instanceof Expression\ClassConstantFetchNode => [self::isThisClass($node) && self::isInClass($node) ? 'static::class' : null, self::Calls],
			$node instanceof Expression\FunctionCallNode => $this->readCall($node, $context),
			default => [null, null],
		};
		if ($replacement === null || ($decision === self::Calls && !$this->calls)) {
			return;
		}

		$uncertainty = $node instanceof Expression\FunctionCallNode ? GlobalCalls::findUncertainty($node, $context) : null;
		if (!$context->report(
			$node,
			'The class name must be obtained with ' . Violation::formatCode((string) preg_replace('~\s+~', ' ', $replacement)) . '.',
			decision: $decision,
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			return;
		}

		$node->replaceWith((new Builder)->expression($replacement));
	}


	/**
	 * The `::class` form of the call and the decision asking for it; a null form for a call that stays.
	 * @return array{?string, ?string}
	 */
	private function readCall(Expression\FunctionCallNode $call, RuleContext $context): array
	{
		$function = GlobalCalls::findFunction($call, ['get_class' => true, 'get_called_class' => true, 'get_parent_class' => true], $context);
		if ($function === null) {
			return [null, null];
		}

		$count = count($call->arguments->items);
		// the parameter of get_class(), by name or by position; get_parent_class() names it object_or_class
		// and is rewritten only without an argument
		$object = $call->arguments->findArgument('object', 0)?->value;
		$isThis = $object instanceof Expression\VariableNode && $object->isThis();
		return match (true) {
			$function === 'get_class' && $count === 0 => [self::isInClass($call) ? 'self::class' : null, self::Calls],
			$function === 'get_class' && $count === 1 && $isThis => [self::isInClass($call) ? 'static::class' : null, self::Calls],
			$function === 'get_class' && $count === 1 && $object !== null && $this->onObjects && !$call->hasInnerComment() => [
				($object->isDereferenceable(DereferenceKind::StaticAccess) ? $object->text : '(' . $object->text . ')') . '::class',
				self::OnObjects,
			],
			$function === 'get_called_class' && $count === 0 => [self::isInClass($call) ? 'static::class' : null, self::Calls],
			$function === 'get_parent_class' && $count === 0 => [self::hasParent($call) ? 'parent::class' : null, self::Calls],
			default => [null, null],
		};
	}


	private static function isInClass(Node $node): bool
	{
		return $node->findClassScope() !== null;
	}


	/** Whether the fetch is `$this::class`, which names the class the object really has, as `static::class` does. */
	private static function isThisClass(Expression\ClassConstantFetchNode $fetch): bool
	{
		return $fetch->class instanceof Expression\VariableNode
			&& $fetch->class->isThis()
			&& $fetch->name instanceof Nodes\IdentifierNode
			&& $fetch->name->equals('class');
	}


	/** Whether the call stands in a class that extends another one; elsewhere `parent::class` does not compile. */
	private static function hasParent(Node $node): bool
	{
		$class = $node->findClassScope();
		return ($class instanceof Nodes\Statement\ClassNode || $class instanceof Nodes\AnonymousClassNode)
			&& $class->extends !== null;
	}
}
