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
	private const Required = 'required';

	/** the kind => the decision of the word naming it */
	private const Affixes = [
		'abstract class' => 'naming.abstractClassAffix',
		'interface' => 'naming.interfaceAffix',
		'trait' => 'naming.traitAffix',
		'class' => 'naming.errorAffix',
	];

	/** @var array<string, ?string>  the kind => `forbidden` or `required`, null where it is kept */
	private array $states = [];


	public static function getDecisions(): array
	{
		$state = Domain::state('forbidden', self::Required);
		return [
			new Decision(
				'naming.abstractClassAffix',
				$state,
				'The word `Abstract` in front of the name of an abstract class, `AbstractFoo`, which is reported and never renamed',
			),
			new Decision(
				'naming.interfaceAffix',
				$state,
				'The word `Interface` at the end of the name of an interface, `FooInterface`, which is reported and never renamed',
			),
			new Decision(
				'naming.traitAffix',
				$state,
				'The word `Trait` at the end of the name of a trait, `FooTrait`, which is reported and never renamed',
			),
			new Decision(
				'naming.errorAffix',
				Domain::state(),
				'The word `Error` at the end of the name of a plain class, `FooError`, which is reported and never renamed',
			),
		];
	}


	public function configure(Values $values): void
	{
		foreach (self::Affixes as $kind => $path) {
			$this->states[$kind] = $values->isKept($path) ? null : $values->get($path)->getWord();
		}
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
		$state = $this->states[$kind] ?? null;
		if ($state === null) {
			return;
		} elseif ($state === self::Required) {
			// the conventional place of the word: in front of an abstract class, behind an interface or a trait
			foreach ($words as $word) {
				if ($word === 'Abstract' && !self::hasPrefix($text, $word)) {
					$context->report(
						$name,
						"The name of the $kind `$text` must start with `$word`.",
						fixable: false,
						decision: self::Affixes[$kind],
					);
				} elseif ($word !== 'Abstract' && !self::hasSuffix($text, $word)) {
					$context->report(
						$name,
						"The name of the $kind `$text` must end with `$word`.",
						fixable: false,
						decision: self::Affixes[$kind],
					);
				}
			}

			return;
		}

		foreach ($words as $word) {
			$length = strlen($word);
			if (self::hasPrefix($text, $word)) {
				$context->report(
					$name,
					'Useless prefix `' . substr($text, 0, $length) . "` in the name of the $kind `$text`.",
					fixable: false,
					decision: self::Affixes[$kind],
				);
			}

			if (self::hasSuffix($text, $word)) {
				$context->report(
					$name,
					'Useless suffix `' . substr($text, -$length) . "` in the name of the $kind `$text`.",
					fixable: false,
					decision: self::Affixes[$kind],
				);
			}
		}

		if ($kind === 'class' && self::hasSuffix($text, 'Error')) {
			$context->report(
				$name,
				'Useless suffix `' . substr($text, -5) . "` in the name of the class `$text`.",
				fixable: false,
				decision: self::Affixes[$kind],
			);
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
