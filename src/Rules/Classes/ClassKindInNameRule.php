<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage, Values};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Statement\{ClassNode, InterfaceNode, TraitNode};
use function strlen;


/**
 * Whether the kind of a type is repeated in its name: `forbidden` reports `AbstractFoo` for an abstract class,
 * `FooInterface` for an interface, `FooTrait` for a trait and `FooError` for a plain class, `required` reports an
 * interface, a trait or an abstract class whose name does not carry the word its kind is named by. Reported either
 * way, never fixed, because renaming a type is not a change of one file.
 */
#[RuleInfo(Stage::Structure)]
final class ClassKindInNameRule extends NodeRule
{
	private const Forbidden = 'forbidden';
	private const Required = 'required';
	private const Path = 'naming.classKindInName';

	private string $state = self::Forbidden;


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Path, Domain::state(self::Forbidden, self::Required), 'The word of its kind in the name of an interface, a trait or an abstract class, `FooInterface`, `FooTrait`, `AbstractFoo`, and `Error` at the end of the name of a plain class, which is reported and never renamed'),
		];
	}


	public function configure(Values $values): void
	{
		$this->state = $values->get(self::Path)->getWord();
	}


	public function getVisitedNodes(): array
	{
		return [ClassNode::class, InterfaceNode::class, TraitNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$name, $words] = match (true) {
			$node instanceof ClassNode => [$node->name, $node->modifiers->abstract ? ['Abstract'] : []],
			$node instanceof InterfaceNode => [$node->name, ['Interface']],
			$node instanceof TraitNode => [$node->name, ['Trait']],
			default => [null, []],
		};
		if ($name === null) {
			return;
		}

		$text = $name->token->text;
		$kind = match (true) {
			$node instanceof InterfaceNode => 'interface',
			$node instanceof TraitNode => 'trait',
			$words !== [] => 'abstract class',
			default => 'class',
		};
		if ($this->state === self::Required) {
			// the conventional place of the word: in front of an abstract class, behind an interface or a trait
			foreach ($words as $word) {
				if ($word === 'Abstract' && !self::hasPrefix($text, $word)) {
					$context->report($name, "The name of the $kind `$text` must start with `$word`.", fixable: false);
				} elseif ($word !== 'Abstract' && !self::hasSuffix($text, $word)) {
					$context->report($name, "The name of the $kind `$text` must end with `$word`.", fixable: false);
				}
			}

			return;
		}

		foreach ($words as $word) {
			$length = strlen($word);
			if (self::hasPrefix($text, $word)) {
				$context->report($name, 'Useless prefix `' . substr($text, 0, $length) . "` in the name of the $kind `$text`.", fixable: false);
			}

			if (self::hasSuffix($text, $word)) {
				$context->report($name, 'Useless suffix `' . substr($text, -$length) . "` in the name of the $kind `$text`.", fixable: false);
			}
		}

		if ($node instanceof ClassNode && !$node->modifiers->abstract && self::hasSuffix($text, 'Error')) {
			$context->report($name, 'Useless suffix `' . substr($text, -5) . "` in the name of the class `$text`.", fixable: false);
		}
	}


	/** The word is a prefix only where the name breaks after it: `TraitsAware` says what it knows, not what it is. */
	private static function hasPrefix(string $text, string $word): bool
	{
		return strlen($text) > strlen($word)
			&& strcasecmp(substr($text, 0, strlen($word)), $word) === 0
			&& preg_match('~^[A-Z0-9]~', substr($text, strlen($word))) === 1;
	}


	private static function hasSuffix(string $text, string $word): bool
	{
		$suffix = substr($text, -strlen($word));
		return strlen($text) > strlen($word)
			&& strcasecmp($suffix, $word) === 0
			&& (ctype_upper($suffix[0]) || $text[-strlen($word) - 1] === '_');
	}
}
