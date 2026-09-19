<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\ConfigurableRule;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\Classes\MemberMaps;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use Nette\Schema\Context;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\EnumCaseNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;


/**
 * A tool for an annotation a library reads as an attribute now: the project, or a library it stands on, maps the
 * annotation to the attribute written instead, `persistent` to `Nette\Application\Attributes\Persistent` or, with
 * arguments, `crossOrigin` to `Nette\Application\Attributes\Requires(sameOrigin: false)`. The annotation leaves
 * the doc comment, the doc comment goes where nothing else stood in it, and the attribute is written above the
 * declaration, its class the way the file writes a class, imported where it can be.
 *
 * An annotation with anything written after it is reported and left as it is, the map saying nothing of where
 * that would go in the attribute, and one whose declaration carries the attribute already is left alone. The fix
 * is not risky: the library reads the two the same way.
 */
#[RuleInfo(
	'dresscode/attribute-for-annotation',
	Stage::Structure,
	description: 'Writes the attribute a project or its libraries read instead of an annotation',
	group: Group::Deprecations,
	modifiesComments: true,
)]
final class AttributeForAnnotationRule extends NodeRule implements ConfigurableRule
{
	private const AttributePattern = '~^\\\\?(\w+(?:\\\\\w+)*)(\(.*\))?$~Ds';

	/** @var array<string, array{string, string}>  lowercased annotation without @ → the class of the attribute and what follows it */
	private array $attributes = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::arrayOf(MemberMaps::code(), Expect::string()->pattern('@?[\w-]+'))
			->description('The annotation, without the @ → the attribute written instead, its class fully qualified, with its arguments where it has any')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $annotation => $code) {
					if ($code !== MemberMaps::Keep && !preg_match(self::AttributePattern, $code)) {
						$context->addError("The attribute '$code' written instead of @$annotation is not a class with its arguments, Class or Class(arguments).", 'dresscode.attributeCode');
					}
				}

				return $options;
			});
	}


	public function configure(array $options): void
	{
		$this->attributes = [];
		foreach ($options as $annotation => $code) {
			if ($code !== MemberMaps::Keep && preg_match(self::AttributePattern, $code, $m)) { // keep is an entry a later layer withdrew
				$this->attributes[strtolower(ltrim((string) $annotation, '@'))] = [$m[1], $m[2] ?? ''];
			}
		}
	}


	public function getVisitedTypes(): array
	{
		return [
			ClassNode::class,
			InterfaceNode::class,
			TraitNode::class,
			EnumNode::class,
			FunctionNode::class,
			MethodNode::class,
			PropertyNode::class,
			ClassConstNode::class,
			EnumCaseNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ClassNode
			&& !$node instanceof InterfaceNode
			&& !$node instanceof TraitNode
			&& !$node instanceof EnumNode
			&& !$node instanceof FunctionNode
			&& !$node instanceof MethodNode
			&& !$node instanceof PropertyNode
			&& !$node instanceof ClassConstNode
			&& !$node instanceof EnumCaseNode
		) {
			return;
		}

		$docComment = $this->attributes === [] ? null : $node->getDocComment();
		if ($docComment === null || $docComment->inInterpolation) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$kept = $codes = [];
		foreach ($tree->children as $child) {
			$attribute = $child instanceof PhpDocTagNode ? $this->attributes[strtolower(ltrim($child->name, '@'))] ?? null : null;
			if ($attribute === null || AnnotationToAttribute::has($node->attributes, $attribute[0])) {
				$kept[] = $child;
				continue;
			}

			$text = $child->value instanceof GenericTagValueNode ? trim($child->value->value) : (string) $child->value;
			$message = "Annotation $child->name is replaced by the attribute #[$attribute[0]$attribute[1]]";
			if ($text !== '') {
				$context->report($node, "$message, but nothing says where what stands after it would go", trivia: $docComment, fixable: false);
				$kept[] = $child;
			} elseif ($context->report($node, $message, trivia: $docComment)) {
				$codes[] = NodeHelpers::spellClass($attribute[0], $node, $context) . $attribute[1];
			} else {
				$kept[] = $child;
			}
		}

		if ($codes !== []) {
			$tree->children = $kept;
			AnnotationToAttribute::apply($node, $node->attributes, $docComment, $tree, $codes, $phpDoc, $context);
		}
	}
}
