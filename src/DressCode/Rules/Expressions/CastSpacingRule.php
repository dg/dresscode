<?php declare(strict_types=1);

namespace DressCode\Rules\Expressions;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\CastNode;
use PhpSyntax\Token;


/**
 * A single space, or none, between a cast and its operand. The spelling of the cast itself is
 * cast-canonical-type.
 */
#[RuleInfo(
	'dresscode/cast-spacing',
	Stage::Formatting,
	description: 'Puts a single space between a cast and its operand',
)]
final class CastSpacingRule extends Rule implements ConfigurableRule
{
	private string $space = ' ';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('single', 'none')->default('single')->description('Between the cast and its operand'),
		]);
	}


	public function configure(array $options): void
	{
		$this->space = $options['spacing'] === 'single' ? ' ' : '';
	}


	public function getVisitedTypes(): array
	{
		return [CastNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof CastNode) {
			return;
		}

		$cast = $node->cast;
		$space = $cast->getTrailingSpace();
		if (
			$space !== null
			&& $space !== $this->space
			&& $context->report($cast, $this->space === '' ? 'No whitespace after a cast' : 'A single space after a cast')
		) {
			$cast->setTrailingSpace($this->space);
		}
	}
}
