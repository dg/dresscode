<?php declare(strict_types=1);

namespace DressCode\Rules\ControlFlow;

use DressCode\Claim;
use DressCode\ConfigurableRule;
use DressCode\Gap;
use DressCode\GapRule;
use DressCode\Line;
use DressCode\RuleInfo;
use DressCode\Space;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Nodes\CatchNode;
use PhpSyntax\Nodes\ElseIfNode;
use PhpSyntax\Nodes\ElseNode;
use PhpSyntax\Nodes\FinallyNode;
use PhpSyntax\Nodes\Statement\DoWhileNode;


/**
 * The keyword continuing a control structure (`else`, `elseif`, `catch`, `finally`, the `while` of `do`)
 * on the line of the closing brace before it, a single space between them, or on the line after it.
 */
#[RuleInfo(
	'dresscode/continuation-position',
	Stage::Formatting,
	description: 'Puts else, elseif, catch, finally and the while of do on the line of the closing brace, or on the next one',
)]
final class ContinuationPositionRule extends GapRule implements ConfigurableRule
{
	private string $position = 'sameLine';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'position' => Expect::anyOf('sameLine', 'nextLine')->default('sameLine')
				->description('On the line of the closing brace before the keyword, or on the line after it'),
		]);
	}


	public function configure(array $options): void
	{
		$this->position = $options['position'];
	}


	public function getClaims(): array
	{
		// a body without braces keeps the keyword where it is
		$wanted = $this->position === 'sameLine' ? new Claim(Space::Single, line: Line::Same) : Claim::nextLine();
		$claim = [fn(Gap $gap) => $gap->token->getPrevious()?->is('}') ?? false ? $wanted : null, null];
		return [
			ElseIfNode::class => ['elseifKeyword' => $claim],
			ElseNode::class => ['elseKeyword' => $claim],
			CatchNode::class => ['catchKeyword' => $claim],
			FinallyNode::class => ['finallyKeyword' => $claim],
			DoWhileNode::class => ['whileKeyword' => $claim],
		];
	}
}
