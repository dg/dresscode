<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{ConfigurableRule, NodeRule, Risk, RuleContext, RuleGroup, RuleInfo, Stage, Violation};
use DressCode\Rules\GlobalCalls;
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{DereferenceKind, Node, Nodes, Parser, Token};
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\Scalar\MagicConstantNode;
use function count;


/**
 * The `::class` form for the name of the current class or its parent instead of `get_class()`, `get_called_class()`,
 * `get_parent_class()` and `__CLASS__`, and with `onObjects` also `$object::class` instead of `get_class($object)`.
 * `$this::class` is `static::class`, which says the same without reaching for the object.
 */
#[RuleInfo(
	'dresscode/getClassNotation',
	Stage::Structure,
	description: 'Uses `::class` instead of `get_class()` and `__CLASS__`',
	group: RuleGroup::Modernization,
)]
final class GetClassNotationRule extends NodeRule implements ConfigurableRule
{
	private bool $onObjects = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'onObjects' => Expect::bool(false)->description('`get_class($object)` becomes `$object::class`'),
		]);
	}


	public function configure(array $options): void
	{
		$this->onObjects = $options['onObjects'];
	}


	public function getVisitedTypes(): array
	{
		return [Expression\FunctionCallNode::class, Expression\ClassConstantFetchNode::class, MagicConstantNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Node) {
			return;
		}

		$replacement = match (true) {
			$node instanceof MagicConstantNode => strcasecmp($node->token->text, '__CLASS__') === 0 && self::isInClass($node) ? 'self::class' : null,
			$node instanceof Expression\ClassConstantFetchNode => self::isThisClass($node) ? 'static::class' : null,
			$node instanceof Expression\FunctionCallNode => $this->describeCall($node, $context),
			default => null,
		};
		if ($replacement === null) {
			return;
		}

		$uncertainty = $node instanceof Expression\FunctionCallNode ? GlobalCalls::findUncertainty($node, $context) : null;
		if (!$context->report(
			$node,
			'The class name must be obtained with ' . Violation::formatCode($replacement),
			risk: $uncertainty === null ? null : Risk::NameUncertain,
			because: $uncertainty,
		)) {
			return;
		}

		$node->replaceWith((new Parser)->parseExpression($replacement));
	}


	private function describeCall(Expression\FunctionCallNode $call, RuleContext $context): ?string
	{
		$function = GlobalCalls::findFunction($call, ['get_class' => true, 'get_called_class' => true, 'get_parent_class' => true], $context);
		if ($function === null) {
			return null;
		}

		$count = count($call->arguments->items);
		// the parameter of get_class(), by name or by position; get_parent_class() names it object_or_class
		// and is rewritten only without an argument
		$object = $call->arguments->findArgument('object', 0)?->value;
		$isThis = $object instanceof Expression\VariableNode && $object->isThis();
		return match (true) {
			$function === 'get_class' && $count === 0 => self::isInClass($call) ? 'self::class' : null,
			$function === 'get_class' && $count === 1 && $isThis => self::isInClass($call) ? 'static::class' : null,
			$function === 'get_class' && $count === 1 && $object !== null && $this->onObjects && !$call->hasInnerComment()
				=> ($object->isDereferenceable(DereferenceKind::StaticAccess) ? $object->text : '(' . $object->text . ')') . '::class',
			$function === 'get_called_class' && $count === 0 => self::isInClass($call) ? 'static::class' : null,
			$function === 'get_parent_class' && $count === 0 => self::hasParent($call) ? 'parent::class' : null,
			default => null,
		};
	}


	private static function isInClass(Node $node): bool
	{
		return $node->findAncestor(Nodes\ClassLikeNode::class) !== null;
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
		$class = $node->findAncestor(Nodes\ClassLikeNode::class);
		return ($class instanceof Nodes\Statement\ClassNode || $class instanceof Nodes\AnonymousClassNode)
			&& $class->extends !== null;
	}
}
