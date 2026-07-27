<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ClassLikeNode;
use PhpSyntax\Nodes\Expression\ArrayDimFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\StaticCallNode;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\Scalar\MagicConstantNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use function count;


/**
 * The `::class` form for the name of the current class instead of `get_class()`, `get_called_class()`,
 * `get_parent_class()` and `__CLASS__`, and with `onObjects` also `$object::class` instead of `get_class($object)`.
 */
#[RuleInfo(
	'dresscode/modern-class-name-reference',
	Stage::Structure,
	description: 'Uses ::class instead of get_class() and __CLASS__',
)]
final class ModernClassNameReferenceRule extends Rule implements ConfigurableRule
{
	private bool $onObjects = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'onObjects' => Expect::bool(false)->description('get_class($object) becomes $object::class, which needs PHP 8.0'),
		]);
	}


	public function configure(array $options): void
	{
		$this->onObjects = $options['onObjects'];
	}


	public function getVisitedTypes(): array
	{
		return [FunctionCallNode::class, MagicConstantNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Node) {
			return;
		}

		$inClass = $node->findAncestor(ClassLikeNode::class) !== null;
		$replacement = match (true) {
			$node instanceof MagicConstantNode => $inClass && strcasecmp($node->token->text, '__CLASS__') === 0 ? 'self::class' : null,
			$node instanceof FunctionCallNode => $this->describeCall($node, $inClass, $context),
			default => null,
		};
		if ($replacement === null || !$context->report($node, "The class name must be obtained with $replacement")) {
			return;
		}

		$node->replaceWith((new Parser)->parseExpression($replacement));
	}


	private function describeCall(FunctionCallNode $call, bool $inClass, RuleContext $context): ?string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$args = $call->args->args->getItems();
		$arg = $args[0] ?? null;
		$first = $arg instanceof ArgumentNode && $arg->name === null && $arg->ellipsis === null ? $arg->expr : null;
		$isThis = $first instanceof VariableNode && $first->name instanceof Token && $first->name->text === '$this';
		return match (true) {
			!$resolver->isGlobalFunctionCall($call) => null,
			$resolver->isGlobalFunctionCall($call, 'get_class') && count($args) === 0 => $inClass ? 'self::class' : null,
			$resolver->isGlobalFunctionCall($call, 'get_class') && count($args) === 1 && $isThis => $inClass ? 'static::class' : null,
			$resolver->isGlobalFunctionCall($call, 'get_class') && count($args) === 1 && $first !== null && $this->onObjects && !$call->hasComment()
				=> (self::isPrimary($first) ? trim((string) $first) : '(' . trim((string) $first) . ')') . '::class',
			$resolver->isGlobalFunctionCall($call, 'get_called_class') && count($args) === 0 => $inClass ? 'static::class' : null,
			$resolver->isGlobalFunctionCall($call, 'get_parent_class') && count($args) === 0 => $inClass ? 'parent::class' : null,
			default => null,
		};
	}


	/** An expression `::class` can follow without parentheses. */
	private static function isPrimary(ExpressionNode $expr): bool
	{
		return $expr instanceof VariableNode
			|| $expr instanceof ArrayDimFetchNode
			|| $expr instanceof PropertyFetchNode
			|| $expr instanceof StaticPropertyFetchNode
			|| $expr instanceof MethodCallNode
			|| $expr instanceof StaticCallNode
			|| $expr instanceof FunctionCallNode
			|| $expr instanceof ParenthesizedNode;
	}
}
