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
use PhpSyntax\Nodes\Expression\BinaryNode;
use PhpSyntax\Token;


/**
 * A single space (or none) on each side of the concatenation operator, unless it sits at a line break.
 */
#[RuleInfo(
	'dresscode/concat-spacing',
	Stage::Formatting,
	description: 'Puts a single space around the concatenation operator',
)]
final class ConcatSpacingRule extends Rule implements ConfigurableRule
{
	private string $space = ' ';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'spacing' => Expect::anyOf('single', 'none')->default('single'),
		]);
	}


	public function configure(array $options): void
	{
		$this->space = $options['spacing'] === 'single' ? ' ' : '';
	}


	public function getVisitedTypes(): array
	{
		return [BinaryNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof BinaryNode || $node->operator->text !== '.') {
			return;
		}

		foreach ([$node->operator->getPrevious(), $node->operator] as $token) {
			$space = $token?->getTrailingSpace();
			if (
			    $token !== null
			    && $space !== null
			    && $space !== $this->space
			    && $context->report($node->operator, $this->space === '' ? 'No whitespace around the concatenation operator' : 'A single space around the concatenation operator')
			) {
				$token->setTrailingSpace($this->space);
			}
		}
	}
}
