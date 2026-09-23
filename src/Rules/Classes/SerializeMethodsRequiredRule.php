<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\{MemberKind, Types};
use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AnonymousClassNode, NameNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\ClassNode;


/**
 * A class implementing `Serializable`, which PHP 8.1 deprecated, has `__serialize()` and `__unserialize()` as well,
 * which PHP then calls instead, and which take its place once the interface goes. The rule reports a class lacking
 * either and writes neither: what the two hold is the serialized form of the class, and stored payloads have to
 * keep loading. An abstract class is left alone, as PHP leaves it. Without the types, a class whose parent declares
 * the methods is not told from one lacking them, so a class extending another is reported only where the types say
 * it lacks them.
 */
#[RuleInfo(Stage::Structure, analyses: [Types::class, NameResolver::class])]
final class SerializeMethodsRequiredRule extends NodeRule
{
	private const Methods = ['__serialize', '__unserialize'];


	public static function getDecisions(): array
	{
		return [new Decision('correctness.__serialize', Domain::state('required'), '`__serialize()` and `__unserialize()` in a class implementing `Serializable`')];
	}


	public function getVisitedNodes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof AnonymousClassNode)
			|| ($node instanceof ClassNode && $node->modifiers->abstract)
			|| ($serializable = self::findSerializable($node, $context)) === null
		) {
			return;
		}

		$own = [];
		foreach ($node->members as $member) {
			if ($member instanceof MethodNode && in_array(strtolower($member->name->text), self::Methods, true)) {
				$own[strtolower($member->name->text)] = true;
			}
		}

		$missing = array_diff(self::Methods, array_keys($own));
		if ($missing !== [] && $node->extends !== null) {
			$types = $context->findAnalysis(Types::class);
			$class = $node instanceof ClassNode ? $context->getAnalysis(NameResolver::class)->getDeclaredName($node) : null;
			if ($types === null || $class === null) {
				return;
			}
			$missing = array_filter($missing, fn(string $method) => !$types->hasMember($class, MemberKind::Method, $method));
		}

		if ($missing !== []) {
			$context->report(
				$serializable,
				'A class implementing the deprecated `Serializable` must implement `__serialize()` and `__unserialize()`.',
				fixable: false,
			);
		}
	}


	/** The name of `Serializable` among the interfaces the class implements. */
	private static function findSerializable(ClassNode|AnonymousClassNode $class, RuleContext $context): ?NameNode
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return array_find($class->implements?->getItems() ?? [], fn($name) => strcasecmp($resolver->resolveClass($name), 'Serializable') === 0);
	}
}
