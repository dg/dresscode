<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Whitespace;

use DressCode\{Claim, Decision, Gap, GapRule, Line, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\Node;
use PhpSyntax\Nodes\{Member, ParameterNode, SeparatedNodeList, Statement};


/**
 * The attributes of a declaration (a class, a function, a method, a property, a constant, a case of an enum, a hook
 * of a property whose hooks span lines) stand on lines of their own right above it: every group on its own line, no
 * blank line between the groups, and the declaration on the line after the last one. The attributes of a parameter
 * do so in a list of parameters spread over lines and share the line of the parameter in a list on one line.
 * Attributes on a closure, an anonymous class or a hook among hooks written on one line may share the line.
 */
#[RuleInfo(Stage::Formatting)]
final class AttributePositionRule extends GapRule
{
	private const Declaration = 'multiline.attributes.declaration';
	private const Parameter = 'multiline.attributes.parameter';

	private const Declarations = [
		Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class,
		Statement\FunctionNode::class, Member\MethodNode::class, Member\PropertyNode::class, Member\ClassConstNode::class,
		Member\EnumCaseNode::class,
	];

	private bool $declaration = true;

	private bool $parameter = true;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Declaration, new Words(['ownLines' => 'every group on a line of its own above the declaration']), 'The attributes of a class, a function, a method, a property, a constant, a case of an enum or a hook of a property whose hooks span lines stand right above it, no blank line between the groups; those of a closure, an anonymous class or a hook in hooks written on one line may share its line'),
			new Decision(self::Parameter, new Words(['ownLines' => 'every group on a line of its own in a list of parameters spread over lines, on the line of the parameter in a list on one line']), 'Where the attributes of a parameter stand'),
		];
	}


	public function configure(Values $values): void
	{
		$this->declaration = !$values->isKept(self::Declaration);
		$this->parameter = !$values->isKept(self::Parameter);
	}


	public function getClaims(): array
	{
		$claims = [];
		if ($this->declaration) {
			$own = new Claim(line: Line::Next, blankLines: 0, decision: self::Declaration);
			$next = Claim::nextLine()->withDecision(self::Declaration);
			$claims = array_fill_keys(self::Declarations, [
				'attributes:item' => [fn(Gap $gap) => $gap->index > 0 ? $own : null, null],
				'attributes' => [null, $next],
			]);
			$claims[Member\PropertyHookNode::class] = [
				'attributes:item' => [fn(Gap $gap) => $gap->index > 0 && self::areHooksMultiline($gap) ? $own : null, null],
				'attributes' => [null, fn(Gap $gap) => self::areHooksMultiline($gap) ? $next : null],
			];
		}

		if ($this->parameter) {
			$own = new Claim(line: Line::Next, blankLines: 0, decision: self::Parameter);
			$next = Claim::nextLine()->withDecision(self::Parameter);
			$shared = new Claim(line: Line::Same, decision: self::Parameter);
			$claims[ParameterNode::class] = [
				'attributes:item' => [
					fn(Gap $gap) => $gap->index > 0 ? (self::isListMultiline($gap, $gap->value->parent?->parent) ? $own : $shared) : null,
					null,
				],
				'attributes' => [null, fn(Gap $gap) => self::isListMultiline($gap, $gap->value->parent?->parent) ? $next : $shared],
			];
		}

		return $claims;
	}


	/** Whether the hooks the gap stands among span lines, as their shape at their first gap of the pass says. */
	private static function areHooksMultiline(Gap $gap): bool
	{
		$owner = $gap->token->findAncestor(Member\PropertyHookNode::class)?->parent?->parent;
		if (
			(!$owner instanceof Member\PropertyNode && !$owner instanceof ParameterNode)
			|| $owner->hooks === null
			|| $owner->openBrace === null
			|| $owner->closeBrace === null
		) {
			return false;
		}

		$hooks = $owner->hooks;
		return $gap->once($hooks, fn(): bool => NodeHelpers::isMultiline($owner->openBrace, $hooks->getItems(), $owner->closeBrace));
	}


	/** Whether the list of parameters the parameter stands in spans lines, as its shape at its first gap of the pass says. */
	private static function isListMultiline(Gap $gap, ?Node $param): bool
	{
		$list = $param?->parent;
		if (!$param instanceof ParameterNode || !$list instanceof SeparatedNodeList) {
			return false;
		}

		return $gap->once($list, function () use ($list): bool {
			$open = $list->getFirstToken()?->getPrevious();
			$close = $list->getLastToken()?->getNext();
			return $open !== null && $close !== null && NodeHelpers::isMultiline($open, $list->getItems(), $close);
		});
	}
}
