<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\PhpDoc;
use DressCode\{ConfigurableRule, NodeRule, Risk, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\ClassNode;


/**
 * A class documented as `@internal` is `final`: nothing outside the package may extend it anyway.
 * A class with an exempt annotation, an entity for instance, is left alone.
 *
 * Every fix is risky: a class of the package extending it elsewhere becomes a fatal error, which only the project,
 * the one place such a child may live, can rule out.
 */
#[RuleInfo(
	'dresscode/finalInternalClass',
	Stage::Structure,
	description: 'Makes classes annotated as internal final',
)]
final class FinalInternalClassRule extends NodeRule implements ConfigurableRule
{
	/** @var list<string> */
	private array $requiredAnnotations = [];

	/** @var list<string> */
	private array $exemptAnnotations = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'requiredAnnotations' => Expect::listOf('string')->default(['@internal'])->description('Annotations a class must all carry to become final'),
			'exemptAnnotations' => Expect::listOf('string')->default(['@final', '@Entity', '@ORM\Entity', '@ORM\Mapping\Entity', '@Mapping\Entity', '@Document', '@ODM\Document'])
				->description('Annotations that keep a class as it is'),
		]);
	}


	public function configure(array $options): void
	{
		$this->requiredAnnotations = array_values(array_map('strtolower', $options['requiredAnnotations']));
		$this->exemptAnnotations = array_values(array_map('strtolower', $options['exemptAnnotations']));
	}


	public function getVisitedTypes(): array
	{
		return [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassNode
			|| $node->modifiers->isFinal()
			|| $node->modifiers->isAbstract()
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$tags = [];
		foreach ($context->getAnalysis(PhpDoc::class)->parse($docComment)->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				$tags[] = strtolower($child->name);
			}
		}

		if (
			array_diff($this->requiredAnnotations, $tags) !== []
			|| array_intersect($this->exemptAnnotations, $tags) !== []
			|| !$context->report($node->classKeyword, 'An internal class must be final', risk: Risk::BehaviorChanges)
		) {
			return;
		}

		$others = $node->modifiers->getTokens();
		foreach ($others as $other) {
			$node->modifiers->removeToken($other);
		}

		$node->modifiers->append(Token::fromText('final'));
		foreach ($others as $other) {
			$node->modifiers->append($other);
		}
	}
}
