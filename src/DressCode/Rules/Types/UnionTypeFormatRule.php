<?php declare(strict_types=1);

namespace DressCode\Rules\Types;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Type\IntersectionTypeNode;
use PhpSyntax\Nodes\Type\NamedTypeNode;
use PhpSyntax\Nodes\Type\UnionTypeNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use function count;


/**
 * A nullable native type is written `?Foo`, not `Foo|null`; in a longer union `null` comes last, and the
 * other types may be asked to come in alphabetical order. Spaces around `|` are the matter of
 * dresscode/type-hint-spacing.
 */
#[RuleInfo(
	'dresscode/union-type-format',
	Stage::Structure,
	description: 'Writes a nullable type as ?T, puts null last in a union type and may sort the rest',
)]
final class UnionTypeFormatRule extends Rule implements ConfigurableRule
{
	private bool $shortNullable = true;
	private string $nullPosition = 'last';
	private bool $alphabetically = false;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'shortNullable' => Expect::bool(true)->description('T|null is written ?T'),
			'nullPosition' => Expect::anyOf('last', 'first')->default('last')->description('Where null stands in a union of three or more types'),
			'alphabetically' => Expect::bool(false)->description('The other types of a union are sorted by name, case-insensitively'),
		]);
	}


	public function configure(array $options): void
	{
		$this->shortNullable = $options['shortNullable'];
		$this->nullPosition = $options['nullPosition'];
		$this->alphabetically = $options['alphabetically'];
	}


	public function getVisitedTypes(): array
	{
		return [UnionTypeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof UnionTypeNode || $node->hasComment()) {
			return;
		}

		$members = $node->types->getItems();
		$nulls = $others = [];
		$dnf = false;
		foreach ($members as $member) {
			$dnf = $dnf || $member instanceof IntersectionTypeNode;
			if ($member instanceof NamedTypeNode && strtolower($member->name->getName()) === 'null') {
				$nulls[] = $member;
			} else {
				$others[] = trim((string) $member);
			}
		}

		if ($others === [] || ($nulls === [] && !$this->alphabetically)) {
			return;
		}

		if ($nulls !== [] && $this->shortNullable && count($others) === 1 && !$dnf) {
			if ($context->report($node, "The nullable type must be written '?" . $others[0] . "'")) {
				$node->replaceWith((new Parser)->parseType('?' . $others[0]));
			}

			return;
		}

		if ($this->alphabetically) {
			usort($others, strcasecmp(...));
		}

		$expected = match (true) {
			$nulls === [] => $others,
			$this->nullPosition === 'last' => [...$others, 'null'],
			default => ['null', ...$others],
		};
		$actual = array_map(fn(Node $member) => trim((string) $member), $members);
		if ($actual === $expected) {
			return;
		}

		$message = $this->alphabetically && array_values(array_diff($actual, ['null'])) !== $others
			? 'The types of a union type must be in alphabetical order'
			: "null must come {$this->nullPosition} in a union type";
		if ($context->report($node, $message)) {
			$node->replaceWith((new Parser)->parseType(implode('|', $expected)));
		}
	}
}
