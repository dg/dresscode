<?php declare(strict_types=1);

namespace DressCode\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;
use function sprintf;


/**
 * Slots of syntax tree nodes are written only through their setters, which keep parents and the token index
 * consistent; direct writes are allowed only in the paths that implement them.
 * @implements Rule<Expr>
 */
final class NodeSlotWriteRule implements Rule
{
	/** @param list<string> $allowedPaths */
	public function __construct(
		private readonly array $allowedPaths,
	) {
	}


	public function getNodeType(): string
	{
		return Expr::class;
	}


	public function processNode(Node $node, Scope $scope): array
	{
		if (
			!$node instanceof Expr\Assign && !$node instanceof Expr\AssignOp && !$node instanceof Expr\AssignRef
			|| PathMatcher::matches($scope->getFile(), $this->allowedPaths)
		) {
			return [];
		}

		$target = $node->var;
		while ($target instanceof Expr\ArrayDimFetch) {
			$target = $target->var;
		}

		if (!$target instanceof Expr\PropertyFetch || !$target->name instanceof Identifier) {
			return [];
		}

		$type = $scope->getType($target->var);
		$property = $target->name->toString();
		$isNode = (new ObjectType('PhpSyntax\Node'))->isSuperTypeOf($type)->yes();
		$isTokenParent = $property === 'parent' && (new ObjectType('PhpSyntax\Token'))->isSuperTypeOf($type)->yes();
		if (!$isNode && !$isTokenParent) {
			return [];
		}

		return [
			RuleErrorBuilder::message(sprintf('Slot $%s of %s must be written through its setter.', $property, $type->describe(VerbosityLevel::typeOnly())))
				->identifier('dresscode.nodeSlotWrite')
				->build(),
		];
	}
}
