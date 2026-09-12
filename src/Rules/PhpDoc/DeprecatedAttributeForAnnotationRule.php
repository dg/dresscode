<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\DeprecatedTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\AttributeGroupNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\EnumCaseNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * `@deprecated` tells a reader and an IDE, `#[\Deprecated]` of PHP 8.4 tells PHP as well, so the annotation
 * becomes the attribute and leaves the doc comment. What stood after the tag is split the way the attribute
 * takes it: a version at the front becomes `since`, the rest `message`.
 *
 * Functions and methods take the attribute from 8.4, constants and enum cases from 8.5, so the rule asks
 * which version the code targets before it writes one. A declaration that carries the attribute already
 * keeps its annotation, the two saying the same thing in one place each.
 *
 * Every fix is risky, and that is the point of it: a call of the declaration begins to raise
 * E_USER_DEPRECATED, which the annotation never did, so a run that logs notices starts logging them.
 */
#[RuleInfo(
	'dresscode/deprecated-attribute-for-annotation',
	Stage::Structure,
	description: 'Replaces the @deprecated annotation with the #[\Deprecated] attribute',
	group: Group::Modernization,
	modifiesComments: true,
	requires: ['php' => '>=8.4'],
	risky: true,
)]
final class DeprecatedAttributeForAnnotationRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [FunctionNode::class, MethodNode::class, ClassConstNode::class, EnumCaseNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$attributes = match (true) {
			$node instanceof FunctionNode, $node instanceof MethodNode => $node->attributes,
			$node instanceof ClassConstNode, $node instanceof EnumCaseNode => version_compare($context->getPhpVersion(), '8.5', '>=')
				? $node->attributes
				: null,
			default => null,
		};
		$docComment = $node instanceof Node ? $node->getDocComment() : null;
		if (
			$attributes === null
			|| $docComment === null
			|| $docComment->inInterpolation
			|| self::isMarked($attributes)
		) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$kept = [];
		$arguments = null;
		foreach ($tree->children as $child) {
			if (
				!$child instanceof PhpDocTagNode
				|| !$child->value instanceof DeprecatedTagValueNode
				|| $arguments !== null // a second annotation stays where it is
				|| !$context->report($node, 'The deprecation must be written with the #[\Deprecated] attribute', trivia: $docComment)
			) {
				$kept[] = $child;
				continue;
			}

			$arguments = self::readArguments($child->value->description);
		}

		if ($arguments === null) {
			return;
		}

		// the doc comment is edited while it still stands where the node looks for it, in front of this token
		$first = $node->getFirstToken();
		$tree->children = $kept;
		if (PhpDoc::isEmpty($tree)) {
			$node->removeDocComment();
		} else {
			$node->replaceDocComment($phpDoc->print($tree, $docComment));
		}

		$template = (new Parser)->parseFragment(AttributeGroupNode::class, "#[\\Deprecated$arguments]");
		$attributes->append($template);
		if ($first !== null) {
			// the attribute takes over what stood in front of the declaration, the doc comment among it
			$indentation = $first->getIndentation();
			$template->getFirstToken()?->setLeadingTrivia($first->leadingTrivia);
			$first->setLeadingTrivia([
				new Trivia(TriviaKind::EndOfLine, $context->getStyle()->eol),
				new Trivia(TriviaKind::Whitespace, $indentation),
			]);
		}
	}


	/** The arguments the attribute takes, written out of what stood after the tag: a version and a message. */
	private static function readArguments(string $description): string
	{
		$description = trim($description);
		$since = preg_match('~^(\d+(\.\d+)*)\s*(.*)$~sD', $description, $m) === 1 ? $m[1] : null;
		$message = trim($since === null ? $description : $m[3]);
		$arguments = array_filter([
			'message' => $message === '' ? null : $message,
			'since' => $since,
		]);
		$written = [];
		foreach ($arguments as $name => $value) {
			$written[] = "$name: " . var_export(preg_replace('~\s+~', ' ', $value), true);
		}

		return $written === [] ? '' : '(' . implode(', ', $written) . ')';
	}


	/**
	 * Whether the declaration carries the attribute already, whichever way its name is written.
	 * @param  NodeList<AttributeGroupNode>  $attributes
	 */
	private static function isMarked(NodeList $attributes): bool
	{
		return array_any(
			$attributes->getItems(),
			fn(Node $group) => preg_match('~(^|\W)Deprecated\b~i', $group->text) === 1,
		);
	}
}
