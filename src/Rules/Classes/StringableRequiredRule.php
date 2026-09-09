<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AnonymousClassNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * A class with `__toString()` says that it is `Stringable`. PHP 8.0 gives it that interface whether it says
 * so or not, so the fix adds nothing at run time; it puts in the declaration what the code already is, where
 * a reader and a static analyser look for it.
 *
 * The name is written with the leading backslash, which dresscode/name-notation turns into an import where
 * the standard asks for one. A class that names the interface already is left alone, and one that has it
 * from a parent is not seen, which costs nothing: PHP takes a repeated interface.
 */
#[RuleInfo(
	'dresscode/stringable-required',
	Stage::Structure,
	description: 'Declares a class with a __toString() method as Stringable',
	group: Group::Modernization,
	requires: ['php' => '>=8.0'],
)]
final class StringableRequiredRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassNode::class, AnonymousClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			(!$node instanceof ClassNode && !$node instanceof AnonymousClassNode)
			|| !self::hasToString($node)
			|| self::namesStringable($node, $context)
			|| !$context->report($node->classKeyword, 'The class with a __toString() method must be declared Stringable')
		) {
			return;
		}

		$template = (new Parser)->parseStatement('class Template implements \Stringable {}');
		assert($template instanceof ClassNode && $template->implements !== null && $template->implementsKeyword !== null);
		if ($node->implements === null) {
			[$keyword, $names] = [$template->implementsKeyword, $template->implements];
			$template->implementsKeyword = null;
			$template->implements = null;
			$anchor = ($node->extends ?? ($node instanceof ClassNode ? $node->name : $node->arguments))?->getLastToken()
				?? $node->classKeyword;
			$trailing = self::takeTrailing($anchor, ' ');
			$node->implementsKeyword = $keyword;
			$node->implements = $names;
			$names->getLastToken()?->setTrailingTrivia($trailing);
		} else {
			$name = $template->implements->getItems()[0];
			$template->implements->removeItem($name);
			$name->setEdgeTrivia([], []);
			$trailing = self::takeTrailing($node->implements->getLastToken(), '');
			$node->implements->append($name);
			$name->getLastToken()?->setTrailingTrivia($trailing);
		}
	}


	/**
	 * What ends the line of the token, the token left with the given whitespace instead, so that the clause
	 * written after it takes over the end of the line.
	 * @return list<Trivia>
	 */
	private static function takeTrailing(?Token $anchor, string $replacement): array
	{
		if ($anchor === null) {
			return [];
		}

		$trailing = $anchor->trailingTrivia;
		$anchor->setTrailingTrivia($replacement === '' ? [] : [new Trivia(TriviaKind::Whitespace, $replacement)]);
		return $trailing;
	}


	private static function hasToString(ClassNode|AnonymousClassNode $class): bool
	{
		return array_any(
			$class->members->getItems(),
			fn(Node $member) => $member instanceof MethodNode && strcasecmp($member->name->text, '__toString') === 0,
		);
	}


	private static function namesStringable(ClassNode|AnonymousClassNode $class, RuleContext $context): bool
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		return array_any(
			$class->implements?->getItems() ?? [],
			fn(NameNode $name) => strcasecmp($resolver->resolveClass($name), 'Stringable') === 0,
		);
	}
}
