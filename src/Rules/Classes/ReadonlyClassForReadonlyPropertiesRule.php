<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Member\{MethodNode, PropertyNode, TraitUseNode};
use PhpSyntax\Nodes\Statement\ClassNode;


/**
 * A class whose every property is readonly is a readonly class, which says it once instead of on every
 * property; `UselessModifierRule` then takes the modifiers the class implies. The class must have
 * a property, because a readonly class with none says nothing; none of them may be static or hooked and the
 * class may not allow dynamic properties, which a readonly class cannot have, nor use a trait, whose properties
 * the file does not show.
 *
 * Only a final class that extends nothing is marked, and from PHP 8.3 an anonymous one. A readonly class may
 * extend and be extended by readonly classes alone; whether anything extends a class that is not final the
 * file does not say, and without the types, a readonly parent is not told from any other. A class that turns
 * out to have a parent or a child of the other kind is not a changed behaviour but a fatal error, which no
 * consent to a risky fix covers.
 */
#[RuleInfo(Stage::Structure, requires: ['php' => '>=8.2'], analyses: [NameResolver::class])]
final class ReadonlyClassForReadonlyPropertiesRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('upgrading.syntax.readonlyClasses', Domain::adopted(), 'A class whose every property is readonly')];
	}


	public function getVisitedNodes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof AnonymousClassNode)
			|| $node->modifiers->readonly
			|| $node->extends !== null
			|| !($node instanceof AnonymousClassNode || $node->modifiers->final)
			|| ($node instanceof AnonymousClassNode && version_compare($context->phpVersion, '8.3', '<'))
			|| $context->getAnalysis(NameResolver::class)->hasAttribute($node, \AllowDynamicProperties::class)
		) {
			return;
		}

		$properties = 0;
		foreach ($node->members as $member) {
			if ($member instanceof PropertyNode) {
				if (!$member->modifiers->readonly || $member->modifiers->static || $member->hooks !== null) {
					return;
				}

				$properties++;

			} elseif ($member instanceof TraitUseNode) {
				return;

			} elseif ($member instanceof MethodNode && $member->isConstructor()) {
				foreach ($member->parameters->getItems() as $parameter) {
					if ($parameter->promoted) {
						if (!$parameter->modifiers->readonly || $parameter->hooks !== null) {
							return;
						}

						$properties++;
					}
				}
			}
		}

		if (
			$properties === 0
			|| !$context->report(
				$node->classKeyword,
				($node instanceof ClassNode ? "The class `{$node->name->token->text}`" : 'The anonymous class') . ', whose every property is readonly, must be readonly itself.',
			)
		) {
			return;
		}

		$node->modifiers->append(Token::fromText('readonly'));
	}
}
