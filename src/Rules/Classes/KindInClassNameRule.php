<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;
use function strlen;


/**
 * Whether the kind of a type is repeated in its name: `forbidden` reports `AbstractFoo`, `FooInterface`,
 * `FooTrait` and `FooError` for a plain class, `required` reports an interface, a trait or an abstract
 * class whose name does not carry the word its kind is named by. Reported either way, never fixed,
 * because renaming a type is not a change of one file.
 */
#[RuleInfo(
	'dresscode/kind-in-class-name',
	Stage::Structure,
	description: 'Decides whether the name of a class, interface or trait repeats its kind',
	decision: 'kind',
)]
final class KindInClassNameRule extends NodeRule implements ConfigurableRule
{
	private const Forbidden = 'forbidden';
	private const Required = 'required';

	private string $kind = self::Forbidden;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'kind' => Expect::anyOf(self::Forbidden, self::Required)->default(self::Forbidden)
				->description('forbidden reports a name repeating the kind, required reports one that does not carry it: FooInterface, FooTrait, AbstractFoo'),
		]);
	}


	public function configure(array $options): void
	{
		$this->kind = $options['kind'];
	}


	public function getVisitedTypes(): array
	{
		return [ClassNode::class, InterfaceNode::class, TraitNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		[$name, $words] = match (true) {
			$node instanceof ClassNode => [$node->name, $node->modifiers->isAbstract() ? ['Abstract'] : []],
			$node instanceof InterfaceNode => [$node->name, ['Interface']],
			$node instanceof TraitNode => [$node->name, ['Trait']],
			default => [null, []],
		};
		if ($name === null) {
			return;
		}

		$text = $name->token->text;
		if ($this->kind === self::Required) {
			// the conventional place of the word: in front of an abstract class, behind an interface or a trait
			foreach ($words as $word) {
				$carries = $word === 'Abstract' ? self::hasPrefix($text, $word) : self::hasSuffix($text, $word);
				if (!$carries) {
					$context->report($name, "The class name must carry the word '$word'");
				}
			}

			return;
		}

		foreach ($words as $word) {
			$length = strlen($word);
			if (self::hasPrefix($text, $word)) {
				$context->report($name, "Useless prefix '" . substr($text, 0, $length) . "' in the class name");
			}

			if (self::hasSuffix($text, $word)) {
				$context->report($name, "Useless suffix '" . substr($text, -$length) . "' in the class name");
			}
		}

		if ($node instanceof ClassNode && !$node->modifiers->isAbstract() && self::hasSuffix($text, 'Error')) {
			$context->report($name, "Useless suffix '" . substr($text, -5) . "' in the class name");
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
		return strlen($text) > strlen($word) && strcasecmp(substr($text, -strlen($word)), $word) === 0;
	}
}
