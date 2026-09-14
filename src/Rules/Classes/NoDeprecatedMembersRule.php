<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Callee;
use DressCode\Analyses\Deprecation;
use DressCode\Analyses\MemberKind;
use DressCode\Analyses\Types;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Token;


/**
 * Constants and methods of classes their declaration deprecates, `$form::FILLED` where `Form::FILLED` says
 * `@deprecated use Form::Filled`, whatever the expression that reaches them: the member is decided by the class
 * declaring it. A deprecation naming a replacement in the same class is fixed by writing that name; any other
 * is reported with what the deprecation says.
 */
#[RuleInfo(
	'dresscode/no-deprecated-members',
	Stage::Structure,
	description: 'Replaces deprecated constants and methods with the member their deprecation names, and reports the rest',
	group: Group::Deprecations,
	requiresTypes: true,
)]
final class NoDeprecatedMembersRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [ClassConstantFetchNode::class, MethodCallNode::class, StaticMethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassConstantFetchNode
			&& !$node instanceof MethodCallNode
			&& !$node instanceof StaticMethodCallNode
		) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$callee = $types->findCallee($node);
		if ($callee === null) {
			return;
		}

		$deprecation = $types->getDeprecation($callee);
		if ($deprecation === null) {
			return;
		}

		$replacement = self::findReplacement($callee, $deprecation);
		$message = $callee->describe() . ' is deprecated' . ($deprecation->description === '' ? '' : ": $deprecation->description");
		if ($replacement === null) {
			$context->report($node->name, $message, fixable: false);
		} elseif ($context->report($node->name, $message) && $node->name instanceof IdentifierNode) {
			$node->name->text = $replacement;
		}
	}


	/** The name written in place of the member: what the deprecation names when it is a member of the same class and kind. */
	private static function findReplacement(Callee $callee, Deprecation $deprecation): ?string
	{
		$class = $deprecation->replacementClass;
		$shortName = substr($callee->declaringClass, (int) strrpos('\\' . $callee->declaringClass, '\\'));
		return $deprecation->replacementName !== null
			&& !str_starts_with($deprecation->replacementName, '$')
			&& $deprecation->replacementIsCall === ($callee->kind !== MemberKind::Constant)
			&& ($class === null || strcasecmp($class, $callee->declaringClass) === 0 || strcasecmp($class, $shortName) === 0)
			? $deprecation->replacementName
			: null;
	}
}
