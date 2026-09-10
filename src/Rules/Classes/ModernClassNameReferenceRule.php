<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\AccessKind;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes;
use PhpSyntax\Nodes\Expression;
use PhpSyntax\Nodes\Scalar\MagicConstantNode;
use PhpSyntax\Parser;
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
final class ModernClassNameReferenceRule extends NodeRule implements ConfigurableRule
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
		return [Expression\FunctionCallNode::class, MagicConstantNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Node) {
			return;
		}

		$inClass = $node->findAncestor(Nodes\ClassLikeNode::class) !== null;
		$replacement = match (true) {
			$node instanceof MagicConstantNode => $inClass && strcasecmp($node->token->text, '__CLASS__') === 0 ? 'self::class' : null,
			$node instanceof Expression\FunctionCallNode => $this->describeCall($node, $inClass, $context),
			default => null,
		};
		if ($replacement === null) {
			return;
		}

		// get_class($object) throws where $object is not one, and ::class on a string gives the string back,
		// so the rewrite changes what the code does wherever the argument is not certainly an object
		$risky = $node instanceof Expression\FunctionCallNode && !str_starts_with($replacement, 'self::')
			&& !str_starts_with($replacement, 'static::') && !str_starts_with($replacement, 'parent::');
		if (!$context->report($node, "The class name must be obtained with $replacement", risky: $risky)) {
			return;
		}

		$node->replaceWith((new Parser)->parseExpression($replacement));
	}


	private function describeCall(Expression\FunctionCallNode $call, bool $inClass, RuleContext $context): ?string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$count = count($call->arguments->items);
		// the parameter of get_class(), by name or by position; the other two functions take none
		$object = $call->arguments->findArgument('object', 0)?->value;
		$isThis = $object instanceof Expression\VariableNode && $object->plainName === 'this';
		return match (true) {
			!$resolver->isGlobalFunctionCall($call) => null,
			$resolver->isGlobalFunctionCall($call, 'get_class') && $count === 0 => $inClass ? 'self::class' : null,
			$resolver->isGlobalFunctionCall($call, 'get_class') && $count === 1 && $isThis => $inClass ? 'static::class' : null,
			$resolver->isGlobalFunctionCall($call, 'get_class') && $count === 1 && $object !== null && $this->onObjects && !$call->hasComment()
				=> ($object->isDereferenceable(AccessKind::ClassName) ? $object->text : '(' . $object->text . ')') . '::class',
			$resolver->isGlobalFunctionCall($call, 'get_called_class') && $count === 0 => $inClass ? 'static::class' : null,
			$resolver->isGlobalFunctionCall($call, 'get_parent_class') && $count === 0 => $inClass ? 'parent::class' : null,
			default => null,
		};
	}
}
