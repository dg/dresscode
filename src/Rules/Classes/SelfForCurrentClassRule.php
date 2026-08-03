<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression\ArrowFunctionNode;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\ClosureNode;
use PhpSyntax\Nodes\Expression\InstanceofNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Token;
use function count;


/**
 * Inside a class, the class refers to itself as `self`, not by its own name: `self::create()`, `new self`.
 * A static method call is a risky fix: `self::` forwards late static binding, so a method using `static` sees
 * the subclass the calling method runs through instead of this class. A closure rebound to another scope,
 * where `self` means that scope, is out of sight of the rule.
 *
 * With `onStatic`, where no subclass can exist, in a final class, an anonymous class and an enum, `static` means
 * this class too and is written `self`: `static::create()`, `new static`, `$x instanceof static`; the return type
 * `static` stays, since it says what a subclass would return. Inside a closure the fix is risky, because a closure
 * bound to an object of another class and a scope of a third one sees `static` and `self` as two different classes.
 */
#[RuleInfo(
	'dresscode/self-for-current-class',
	Stage::Structure,
	description: 'Replaces the name of the current class with self',
)]
final class SelfForCurrentClassRule extends NodeRule implements ConfigurableRule
{
	private bool $onStatic = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'onStatic' => Expect::bool(false)->description('where no subclass can exist, static means this class too and becomes self'),
		]);
	}


	public function configure(array $options): void
	{
		$this->onStatic = $options['onStatic'];
	}


	public function getVisitedTypes(): array
	{
		return $this->onStatic
			? [ClassNode::class, AnonymousClassNode::class, EnumNode::class]
			: [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof ClassNode) {
			$this->replaceOwnName($node, $context);
		}

		if (!$this->onStatic) {
			return;
		}

		if (
			$node instanceof AnonymousClassNode
			|| $node instanceof EnumNode
			|| ($node instanceof ClassNode && $node->modifiers->isFinal())
		) {
			$this->replaceStatic($node, $context);
		}
	}


	private function replaceOwnName(ClassNode $class, RuleContext $context): void
	{
		$own = $class->name->token->text;
		$resolver = $context->getAnalysis(NameResolver::class);
		$namespace = $resolver->getNamespace($class);
		$ownFullName = ($namespace === '' ? '' : $namespace . '\\') . $own;
		foreach ($class->find(NameNode::class) as $name) {
			$parts = $name->parts;
			if (
				count($parts) !== 1
				|| strcasecmp($parts[0], $own) !== 0
				|| strcasecmp($resolver->resolveClass($name), $ownFullName) !== 0
				|| !self::isClassReference($name)
				|| $name->findAncestor(ClassLikeNode::class) !== $class
				|| !$context->report($name, "The current class '$own' must be referenced as 'self'", risky: $name->parent instanceof StaticMethodCallNode)
			) {
				continue;
			}

			$name->text = 'self';
		}
	}


	private function replaceStatic(ClassLikeNode&Node $class, RuleContext $context): void
	{
		foreach ($class->find(NameNode::class) as $name) {
			if (
				strcasecmp($name->text, 'static') !== 0
				|| !self::isClassReference($name)
				|| $name->findAncestor(ClassLikeNode::class) !== $class
				|| !$context->report(
					$name,
					"'static' can mean no class but the current one here and must be written as 'self'",
					risky: self::isInClosure($name, $class),
				)
			) {
				continue;
			}

			$name->text = 'self';
		}
	}


	/** Whether the name stands for a class in an expression: a static access, an instantiation, an instanceof. */
	private static function isClassReference(NameNode $name): bool
	{
		$parent = $name->parent;
		return $parent instanceof StaticMethodCallNode
			|| $parent instanceof ClassConstantFetchNode
			|| $parent instanceof StaticPropertyFetchNode
			|| $parent instanceof NewNode
			|| $parent instanceof InstanceofNode;
	}


	private static function isInClosure(NameNode $name, Node $class): bool
	{
		for ($node = $name->parent; $node !== null && $node !== $class; $node = $node->parent) {
			if ($node instanceof ClosureNode || $node instanceof ArrowFunctionNode) {
				return true;
			}
		}

		return false;
	}
}
