<?php declare(strict_types=1);

/**
 * Every rule of the core names in `RuleInfo::$analyses` the analyses its methods ask for, and those the static
 * methods it calls ask for, however deep. The fixtures find what a run asks for; this finds what a branch no fixture
 * takes would ask for. A helper reached through an instance (a map a rule holds) is not followed.
 */

use DressCode\RuleInfo;
use PhpSyntax\Analyses\{NameResolver, NamespacedSymbols};
use PhpSyntax\Nodes\Expression\{ClassConstantFetchNode, MethodCallNode, StaticMethodCallNode};
use PhpSyntax\Nodes\{IdentifierNode, NameNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Parser;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


/**
 * The methods of the classes under the directory, `Class::method` in lower case, each with the analyses it asks for
 * itself and the static methods it calls.
 * @return array<string, array{list<string>, list<string>}>
 */
function collectMethods(string $dir): array
{
	$parser = new Parser;
	$methods = [];
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $info) {
		$file = $parser->parse((string) file_get_contents($info->getPathname()));
		$resolver = new NameResolver($file, new NamespacedSymbols);
		foreach ($file->find(ClassNode::class) as $class) {
			$className = ltrim($resolver->getNamespace($class) . '\\' . $class->name->text, '\\');
			foreach ($class->find(MethodNode::class) as $method) {
				$asked = $calls = [];
				foreach ($method->find(MethodCallNode::class) as $call) {
					$argument = $call->arguments->items->getItems()[0]->value ?? null;
					if (
						$call->name instanceof IdentifierNode
						&& in_array($call->name->text, ['getAnalysis', 'findAnalysis'], true)
						&& $argument instanceof ClassConstantFetchNode
						&& $argument->class instanceof NameNode
					) {
						$asked[] = $resolver->resolveClass($argument->class);
					}
				}

				foreach ($method->find(StaticMethodCallNode::class) as $call) {
					if ($call->class instanceof NameNode && $call->name instanceof IdentifierNode) {
						$target = in_array(strtolower($call->class->text), ['self', 'static'], true) ? $className : $resolver->resolveClass($call->class);
						$calls[] = strtolower("$target::{$call->name->text}");
					}
				}

				$methods[strtolower("$className::{$method->name->text}")] = [$asked, $calls];
			}
		}
	}

	return $methods;
}


/**
 * The analyses the method asks for, through the static methods it calls too.
 * @param  array<string, array{list<string>, list<string>}>  $methods
 * @param  array<string, true>  $seen
 * @return list<string>
 */
function collectAsked(array $methods, string $method, array $seen = []): array
{
	if (!isset($methods[$method]) || isset($seen[$method])) {
		return [];
	}

	$seen[$method] = true;
	[$asked, $calls] = $methods[$method];
	foreach ($calls as $callee) {
		array_push($asked, ...collectAsked($methods, $callee, $seen));
	}

	return array_values(array_unique($asked));
}


test('a rule names every analysis it or a static helper it calls asks for', function () {
	$methods = collectMethods(__DIR__ . '/../../../src/Rules');
	$missing = [];
	foreach (require __DIR__ . '/rules.provider.php' as [, $rule]) {
		$prefix = strtolower("$rule::");
		$asked = [];
		foreach (array_keys($methods) as $method) {
			if (str_starts_with($method, $prefix)) {
				array_push($asked, ...collectAsked($methods, $method));
			}
		}

		foreach (array_diff(array_unique($asked), RuleInfo::of($rule)->analyses) as $analysis) {
			$missing[] = "$rule asks for $analysis";
		}
	}

	Assert::same([], $missing);
});
