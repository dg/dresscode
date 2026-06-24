<?php declare(strict_types=1);

namespace DressCode\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function sprintf;


/**
 * Keeps the PhpSyntax layer free of dependencies: code in the PhpSyntax namespace
 * may reference only PhpSyntax itself and PHP built-ins, never DressCode, libraries or vendor code.
 * Walks the whole file because PHPStan does not dispatch rules on names in use statements,
 * instantiations or static calls.
 * @implements Rule<FileNode>
 */
final class LayerBoundaryRule implements Rule
{
	private const Layer = 'PhpSyntax\\';


	public function __construct(
		private readonly ReflectionProvider $reflectionProvider,
	) {
	}


	public function getNodeType(): string
	{
		return FileNode::class;
	}


	public function processNode(Node $node, Scope $scope): array
	{
		$errors = [];
		foreach ((new NodeFinder)->findInstanceOf($node->getNodes(), Namespace_::class) as $namespace) {
			if (!str_starts_with($namespace->name?->toString() . '\\', self::Layer)) {
				continue;
			}

			foreach ((new NodeFinder)->findInstanceOf($namespace->stmts, Name::class) as $name) {
				if ($this->isForeign($name->toString())) {
					$errors[] = RuleErrorBuilder::message(sprintf('PhpSyntax must not depend on %s.', $name->toString()))
						->identifier('dresscode.layerBoundary')
						->line($name->getStartLine())
						->build();
				}
			}
		}

		return $errors;
	}


	private function isForeign(string $name): bool
	{
		if (str_starts_with($name, self::Layer)) {
			return false;
		} elseif ($this->reflectionProvider->hasClass($name)) {
			return !$this->reflectionProvider->getClass($name)->isBuiltin();
		} elseif ($this->reflectionProvider->hasFunction(new Name($name), null)) {
			return !$this->reflectionProvider->getFunction(new Name($name), null)->isBuiltin();
		}

		return false;
	}
}
