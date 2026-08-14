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
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Parser\Parser;
use PhpSyntax\Token;
use PhpSyntax\TriviaKind;
use function count;


/**
 * Empty parentheses of an instantiation without arguments, `required` or `forbidden` for each kind of
 * class on its own, `null` to leave that kind alone; PER wants them on `new Foo()` and not on
 * `new class`. `new Foo()->bar()` keeps them whatever the options say, PHP 8.4 needs them there.
 */
#[RuleInfo(
	'dresscode/new-argument-parentheses',
	Stage::Structure,
	description: 'Requires or forbids the empty parentheses of an instantiation',
)]
final class NewArgumentParenthesesRule extends Rule implements ConfigurableRule
{
	private ?string $namedClasses = 'required';
	private ?string $anonymousClasses = 'required';


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'namedClasses' => Expect::anyOf('required', 'forbidden', null)->default('required'),
			'anonymousClasses' => Expect::anyOf('required', 'forbidden', null)->default('required'),
		]);
	}


	public function configure(array $options): void
	{
		$this->namedClasses = $options['namedClasses'];
		$this->anonymousClasses = $options['anonymousClasses'];
	}


	public function getVisitedTypes(): array
	{
		return [NewNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof NewNode && !$node->class instanceof AnonymousClassNode) {
			$wanted = $this->namedClasses;
			$before = $node->class->getLastToken();
		} elseif ($node instanceof AnonymousClassNode) {
			$wanted = $this->anonymousClasses;
			$before = $node->classKeyword;
		} else {
			return;
		}

		if ($wanted === 'required') {
			$this->addParentheses($node, $before, $context);
		} elseif ($wanted === 'forbidden') {
			$this->removeParentheses($node, $context);
		}
	}


	private function addParentheses(NewNode|AnonymousClassNode $node, ?Token $before, RuleContext $context): void
	{
		if (
			$node->args !== null
			|| $before === null
			|| !$context->report($node, 'Missing parentheses after new')
		) {
			return;
		}

		$template = (new Parser)->parseExpression('new Foo()');
		assert($template instanceof NewNode && $template->args !== null);
		$args = $template->args;
		$template->setArgs(null);
		$args->closeParen->setTrailingTrivia($before->trailingTrivia);
		$before->setTrailingTrivia([]);
		$node->setArgs($args);
	}


	private function removeParentheses(NewNode|AnonymousClassNode $node, RuleContext $context): void
	{
		if (
			($args = $node->args) === null
			|| !$args->args->isEmpty()
			|| ($node instanceof NewNode && $node->isDereferenced()) // new Foo()->bar() needs them since PHP 8.4
			|| $args->openParen->hasComment()
			|| $args->closeParen->hasComment()
			|| !$context->report($args, 'Empty parentheses after new')
		) {
			return;
		}

		$before = $args->openParen->getPrevious();
		$trailing = $args->closeParen->trailingTrivia;
		$node->setArgs(null);
		$onlyWhitespace = true;
		foreach ($trailing as $trivia) {
			$onlyWhitespace = $onlyWhitespace && $trivia->kind === TriviaKind::Whitespace;
		}

		$beforeTrailing = $before->trailingTrivia ?? [];
		$beforeEndsWithSpace = $beforeTrailing !== []
			&& $beforeTrailing[count($beforeTrailing) - 1]->kind === TriviaKind::Whitespace;
		if ($before !== null && $trailing !== [] && !($onlyWhitespace && $beforeEndsWithSpace)) {
			$before->setTrailingTrivia([...$beforeTrailing, ...$trailing]);
		}
	}
}
