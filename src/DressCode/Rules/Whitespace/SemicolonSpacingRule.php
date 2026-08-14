<?php declare(strict_types=1);

namespace DressCode\Rules\Whitespace;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;


/**
 * Whitespace around a semicolon on its line: none before it, a single space after it when more
 * follows, as in the head of a for loop; `for (;;)` stays. Either side can be left alone by null.
 */
#[RuleInfo(
	'dresscode/semicolon-spacing',
	Stage::Formatting,
	description: 'Removes whitespace before a semicolon and puts a single space after it',
)]
final class SemicolonSpacingRule extends Rule implements ConfigurableRule
{
	private ?string $before = 'none';
	private ?string $after = 'single';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'before' => Expect::anyOf('none', null)->default('none'),
			'after' => Expect::anyOf('single', null)->default('single'),
		]);
	}


	public function configure(array $options): void
	{
		$this->before = $options['before'];
		$this->after = $options['after'];
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->is(';')) {
			return;
		}

		if ($this->before === 'none') {
			$this->removeSpaceBefore($node, $context);
		}

		if ($this->after === 'single') {
			$this->addSpaceAfter($node, $context);
		}
	}


	private function removeSpaceBefore(Token $node, RuleContext $context): void
	{
		$previous = $node->getPrevious();
		$space = $previous?->getTrailingSpace();
		if (
			$previous !== null
			&& $space !== null
			&& $space !== ''
			&& $context->report($node, 'No whitespace before a semicolon')
		) {
			$previous->setTrailingSpace('');
		}
	}


	private function addSpaceAfter(Token $node, RuleContext $context): void
	{
		$space = $node->getTrailingSpace();
		$next = $node->getNext();
		if (
			$space === null
			|| $space === ' '
			|| $next === null
			|| $next->is(';', ')', TokenKind::EndOfFile, TokenKind::CloseTag, TokenKind::HaltCompilerData)
			|| !$context->report($node, 'A single space after a semicolon')
		) {
			return;
		}

		$node->setTrailingSpace(' ');
	}
}
