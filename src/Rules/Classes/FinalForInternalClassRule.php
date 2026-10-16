<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, DecisionKind, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Names;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\ClassNode;


/**
 * A class documented as `@internal` is `final`: nothing outside the package may extend it anyway. A marker is an
 * annotation written with its `@` or an attribute named by its fully qualified class, and one of them marks a class.
 * A class with an exempt annotation or attribute, an entity for instance, is left alone.
 *
 * Every fix is risky: a class of the package extending it elsewhere becomes a fatal error, which only the project,
 * the one place such a child may live, can rule out.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpDoc::class, NameResolver::class])]
final class FinalForInternalClassRule extends NodeRule
{
	private const Required = 'classes.internalClass.markers';
	private const Exempt = 'classes.internalClass.except';
	private const DefaultExempt = [
		'@final', '@Entity', '@ORM\Entity', '@ORM\Mapping\Entity', '@Mapping\Entity', '@Document', '@ODM\Document',
		'Doctrine\ORM\Mapping\Entity', 'Doctrine\ODM\MongoDB\Mapping\Annotations\Document',
	];

	/** @var list<string> */
	private array $requiredAnnotations = [];

	/** @var list<string> */
	private array $exemptAnnotations = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'classes.internalClass.final',
				Domain::state('required'),
				'The `final` of a class its author marked as internal, which nothing outside the package may extend',
			),
			new Decision(
				self::Required,
				new Names(empty: false),
				'The annotations, with their `@`, and the attributes, by their fully qualified classes, of which one marks a class internal',
				kind: DecisionKind::Parameter,
				default: ['@internal'],
			),
			new Decision(
				self::Exempt,
				new Names,
				'The annotations and the attributes that keep an internal class as it is, an entity for instance',
				kind: DecisionKind::Parameter,
				default: self::DefaultExempt,
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->requiredAnnotations = array_map(fn($name) => strtolower(ltrim($name, '\\')), $values->get(self::Required)->getNames());
		$this->exemptAnnotations = array_map(fn($name) => strtolower(ltrim($name, '\\')), $values->get(self::Exempt)->getNames());
	}


	public function getVisitedNodes(): array
	{
		return [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode || $node->modifiers->final || $node->modifiers->abstract) {
			return;
		}

		$markers = array_map('strtolower', $context->getAnalysis(NameResolver::class)->findAttributeClasses($node));
		$docComment = $node->getDocComment();
		foreach ($docComment === null ? [] : $context->getAnalysis(PhpDoc::class)->parse($docComment)->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				$markers[] = strtolower($child->name);
			}
		}

		if (
			array_intersect($this->requiredAnnotations, $markers) === []
			|| array_intersect($this->exemptAnnotations, $markers) !== []
			|| !$context->report(
				$node->classKeyword,
				"The internal class `{$node->name->token->text}` must be final.",
				risk: Risk::BehaviorChanges,
				because: 'a class of the project elsewhere may extend it',
			)
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
