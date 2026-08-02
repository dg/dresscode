<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\PhpDoc;
use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * A class documented as `@internal` is `final`: nothing outside the package may extend it anyway.
 * A class with an annotation from the exclude list, an entity for instance, is left alone.
 */
#[RuleInfo(
	'dresscode/final-internal-class',
	Stage::Structure,
	description: 'Makes classes annotated as internal final',
)]
final class FinalInternalClassRule extends Rule implements ConfigurableRule
{
	/** @var list<string> */
	private array $include = [];

	/** @var list<string> */
	private array $exclude = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'include' => Expect::listOf('string')->default(['@internal'])->description('Annotations a class must all carry to become final'),
			'exclude' => Expect::listOf('string')->default(['@final', '@Entity', '@ORM\Entity', '@ORM\Mapping\Entity', '@Mapping\Entity', '@Document', '@ODM\Document'])
				->description('Annotations that keep a class as it is'),
		]);
	}


	public function configure(array $options): void
	{
		$this->include = array_values(array_map('strtolower', $options['include']));
		$this->exclude = array_values(array_map('strtolower', $options['exclude']));
	}


	public function getVisitedTypes(): array
	{
		return [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassNode
			|| $node->modifiers->has(TokenKind::Final)
			|| $node->modifiers->has(TokenKind::Abstract)
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$tags = [];
		foreach ($context->getAnalysis(PhpDoc::class)->parse($docComment)->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				$tags[strtolower($child->name)] = true;
			}
		}

		if (
			array_diff($this->include, array_keys($tags)) !== []
			|| array_intersect($this->exclude, array_keys($tags)) !== []
			|| !$context->report($node->classKeyword, 'An internal class must be final')
		) {
			return;
		}

		$final = new Token(TokenKind::Final, 'final');
		$others = $node->modifiers->getTokens();
		if ($others === []) {
			$final->setLeadingTrivia($node->classKeyword->leadingTrivia);
			$node->classKeyword->setLeadingTrivia([]);
		} else {
			$final->setLeadingTrivia($others[0]->leadingTrivia);
			$others[0]->setLeadingTrivia([]);
		}

		$final->setTrailingTrivia([new Trivia(TriviaKind::Whitespace, ' ')]);
		foreach ($others as $other) {
			$node->modifiers->removeToken($other);
		}

		$node->modifiers->append($final);
		foreach ($others as $other) {
			$node->modifiers->append($other);
		}
	}
}
