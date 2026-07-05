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
use function in_array, sprintf;


/**
 * The tree is written only through its setters, which keep parents and the token index consistent: slots of nodes
 * (and the parent of a token) through the node setters, the text and trivia of a token through the token setters.
 * Direct writes are allowed only in the paths that implement them.
 * @implements Rule<Expr>
 */
final class TreeWriteRule implements Rule
{
	/**
	 * @param list<string> $slotWriteAllowed  paths that may write node slots and token parents
	 * @param list<string> $tokenWriteAllowed  paths that may write the text, trivia and index of a token
	 */
	public function __construct(
		private readonly array $slotWriteAllowed,
		private readonly array $tokenWriteAllowed,
	) {
	}


	public function getNodeType(): string
	{
		return Expr::class;
	}


	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node instanceof Expr\Assign && !$node instanceof Expr\AssignOp && !$node instanceof Expr\AssignRef) {
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
		$file = $scope->getFile();
		if (
			in_array($property, ['text', 'leadingTrivia', 'trailingTrivia', 'index', 'indexedBy'], strict: true)
			&& (new ObjectType('PhpSyntax\Token'))->isSuperTypeOf($type)->yes()
		) {
			return PathMatcher::matches($file, $this->tokenWriteAllowed)
				? []
				: [
					RuleErrorBuilder::message(sprintf('Property $%s of a token is written only by the setters of the token and by the index.', $property))
						->identifier('dresscode.tokenWrite')
						->build(),
				];
		}

		$isNode = (new ObjectType('PhpSyntax\Node'))->isSuperTypeOf($type)->yes();
		$isTokenParent = $property === 'parent' && (new ObjectType('PhpSyntax\Token'))->isSuperTypeOf($type)->yes();
		if (!$isNode && !$isTokenParent || PathMatcher::matches($file, $this->slotWriteAllowed)) {
			return [];
		}

		return [
			RuleErrorBuilder::message(sprintf('Slot $%s of %s must be written through its setter.', $property, $type->describe(VerbosityLevel::typeOnly())))
				->identifier('dresscode.nodeSlotWrite')
				->build(),
		];
	}
}
