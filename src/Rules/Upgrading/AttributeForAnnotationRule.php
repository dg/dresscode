<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Parameter, PhpDoc, Types};
use DressCode\{NodeRule, RuleContext, RuleInfo, Stage, Tristate, Values, Violation};
use DressCode\Rules\CodeWriter;
use DressCode\Rules\PhpDoc\AnnotationReplacement;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, PhpDocTagNode, PhpDocTextNode};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, AttributeGroupNode, AttributeNode, PlainNodeList};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, FunctionNode, InterfaceNode, TraitNode};


/**
 * A tool for an annotation a library reads as an attribute now: the project, or a library it stands on, maps the
 * annotation to the attribute written instead, `cached` to `Acme\Attributes\Cached` or, with arguments,
 * `secured` to `Acme\Attributes\Access(public: false)`. The annotation leaves
 * the doc comment, the doc comment goes where nothing else stood in it, and the attribute is written above the
 * declaration, its class the way the file writes a class, imported where it can be.
 *
 * An annotation with anything written after it is reported and left as it is, the map saying nothing of where
 * that would go in the attribute, and one whose declaration carries the attribute already is left alone. The fix
 * is not risky: the library reads the two the same way.
 *
 * A key that is a class, fully qualified, is an attribute the library reads as another one now:
 * `Acme\Attributes\Secured` to `Acme\Attributes\Access(public: false)`. Such an attribute is written anew
 * in its place, its arguments taken over where the map gives the other one none; one with arguments where the map
 * gives some, or on a declaration that carries the other attribute already, is reported and left as it is; where the
 * map keeps the class, only the attribute without arguments is written anew. The key stands for the Doctrine
 * annotation of the class as well, `@Endpoint("/blog", name="blog")` where the imports make `Endpoint` the class,
 * whose arguments the attribute takes over (AnnotationArguments), nested annotations becoming instantiations.
 * Arguments of a shape PHP cannot write, and arguments where the map gives the attribute its own, are reported.
 *
 * A key that is a namespace, `Acme\Validation\*: Acme\Validation\*`, stands for the Doctrine annotation of every
 * class of it, written as the attribute of the class of the same name in the namespace of the value, the same one
 * or another, `Acme\Http\Annotation\*: Acme\Http\Attribute\*`; a key of the class itself comes first, and an
 * attribute is left as it is. With the types of the code, a class that does not exist or is no attribute is reported.
 *
 * With the types, an annotation or an attribute whose arguments leave out a parameter of the constructor of the
 * attribute that has no default is reported and left as it is: the old library guessed the value, `@View()` taking
 * the template from the name of the action, and the attribute would fail when the library reads it.
 */
#[RuleInfo(
	Stage::Structure,
	modifiesComments: true,
	decisions: ['upgrading.libraries.packages', 'upgrading.libraries.attributeForAnnotation'],
	analyses: [PhpDoc::class, Types::class, NameResolver::class],
)]
final class AttributeForAnnotationRule extends NodeRule
{
	public const Map = AnnotationMap::Path;

	private AnnotationMap $map;


	public function configure(Values $values): void
	{
		$this->map = AnnotationMap::fromValues($values);
	}


	public function getVisitedNodes(): array
	{
		return [...AnnotationMap::Declarations, AttributeNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof AttributeNode) {
			$this->enterAttribute($node, $context);
			return;
		} elseif (
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

		$docComment = $this->map->isEmpty() ? null : $node->getDocComment();
		if ($docComment === null) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$kept = $codes = [];
		foreach ($tree->children as $child) {
			$attribute = $child instanceof PhpDocTagNode ? $this->map->findAttribute($child, $node, $context) : null;
			if ($attribute === null || AnnotationReplacement::has($node->attributes, $attribute->class)) {
				$kept[] = $child;
				continue;
			}

			[$arguments, $refusal] = $this->spellArguments($child, $attribute, $node, $context, fn(string $class) => '\\' . $class);
			$types = $context->findAnalysis(Types::class);
			$refusal ??= $types === null || !$attribute->fromNamespace ? null : match ($types->isAttributeClass($attribute->class)) { // a namespace says nothing of its classes
				Tristate::Maybe => ", but `$attribute->class` does not exist",
				Tristate::No => ", but `$attribute->class` is no attribute",
				Tristate::Yes => null,
			};
			$refusal ??= self::findMissingArgument($attribute->class, $arguments, $types);
			$message = "Annotation `$child->name` is replaced by the attribute " . Violation::formatCode("#[$attribute->class$attribute->arguments]");
			if ($refusal !== null) {
				$context->report($node, $message . $refusal . '.', trivia: $docComment, fixable: false);
				$kept[] = $child;
			} elseif ($context->report($node, $message . '.', trivia: $docComment)) {
				[$arguments] = $this->spellArguments($child, $attribute, $node, $context, fn(string $class) => CodeWriter::writeClass($class, $node, $context));
				$codes[] = CodeWriter::writeClass($attribute->class, $node, $context) . $arguments;
			} else {
				$kept[] = $child;
			}
		}

		if ($codes !== []) {
			// the blank line that stood between the description and the annotations goes with them
			while ($kept !== [] && end($kept) instanceof PhpDocTextNode && trim(end($kept)->text) === '') {
				array_pop($kept);
			}

			$tree->children = $kept;
			AnnotationReplacement::writeAttributes($node, $docComment, $tree, $codes, $phpDoc, $context);
		}
	}


	/**
	 * What follows the class of the attribute, its arguments with their parentheses, or why the annotation has none
	 * to write: a Doctrine annotation gives its arguments, where the map writes none, and nothing may follow it.
	 * @param  \Closure(string): string  $spellClass  the class of a nested annotation => the class written
	 * @return array{string, ?string}  the code and the refusal, a clause of the message
	 */
	private function spellArguments(
		PhpDocTagNode $tag,
		AttributeTarget $attribute,
		Node $at,
		RuleContext $context,
		\Closure $spellClass,
	): array
	{
		// only the annotation of a class is a Doctrine one, whose arguments an attribute can take; after a name it is text
		if (!$tag->value instanceof DoctrineTagValueNode || $this->map->hasAnnotation($tag)) {
			$text = $tag->value instanceof GenericTagValueNode ? trim($tag->value->value) : trim((string) $tag->value);
			return [$attribute->arguments, $text === '' ? null : ', but the text after it has no place there'];
		}

		$arguments = AnnotationArguments::write($tag->value->annotation, function (string $name) use ($at, $context, $spellClass): string {
			$replaced = $this->map->findAttributeOfClass(AnnotationMap::resolveAnnotation($name, $at, $context));
			return $replaced === null ? $name : $spellClass($replaced->class);
		});
		return match (true) {
			trim($tag->value->description) !== '' => [$attribute->arguments, ', but the text after it has no place there'],
			$arguments === '' => [$attribute->arguments, null],
			$arguments === null => ['', ', but its arguments are not what an attribute can be given'],
			$attribute->arguments !== '' => ['', ', but the attribute has arguments of its own'],
			default => ["($arguments)", null],
		};
	}


	private function enterAttribute(AttributeNode $node, RuleContext $context): void
	{
		$class = $this->map->isEmpty() ? null : $context->getAnalysis(NameResolver::class)->resolveClass($node->name);
		$attribute = $class === null ? null : $this->map->findReplacedAttribute($class);
		if ($attribute === null) {
			return;
		}

		$arguments = ($node->arguments?->items->getItems() ?? []) === [] ? '' : (string) $node->arguments?->text;
		if (strcasecmp($attribute->class, $class) === 0 && ($attribute->arguments === '' || $arguments !== '')) {
			return;
		}

		$refusal = match (true) {
			$arguments !== '' && $attribute->arguments !== '' => ', but the attribute written instead has arguments of its own',
			self::hasSibling($node, $attribute->class, $context) => ', but the declaration carries that attribute already',
			default => self::findMissingArgument($attribute->class, $arguments === '' ? $attribute->arguments : $arguments, $context->findAnalysis(Types::class)),
		};
		if ($context->report($node, "Attribute `#[$class]` is replaced by " . Violation::formatCode("#[$attribute->class$attribute->arguments]") . ($refusal ?? '') . '.', fixable: $refusal === null)) {
			// the arguments go over as they are, the library reading them the same way
			$code = CodeWriter::writeClass($attribute->class, $node, $context) . ($arguments === '' ? $attribute->arguments : $arguments);
			$group = (new Builder)->fragment(AttributeGroupNode::class, "#[$code]");
			$node->replaceWith($group->items->getItems()[0]->withoutEdgeTrivia());
		}
	}


	/**
	 * Why the attribute cannot be written with the arguments: a parameter of its constructor they leave out has no
	 * default, which PHP refuses when the library reads the attribute (`@Template()`, whose template the old library
	 * guessed). Null where the arguments fill every such parameter, where they unpack an array, and without the types.
	 * @param  string  $arguments  with their parentheses, or empty
	 */
	private static function findMissingArgument(string $class, string $arguments, ?Types $types): ?string
	{
		$parameters = $types?->findMethodParameters($class, '__construct');
		if ($parameters === null) {
			return null;
		}

		$group = (new Builder)->fragment(AttributeGroupNode::class, "#[Attribute$arguments]");
		$positional = 0;
		$named = [];
		foreach ($group->items->getItems()[0]->arguments?->items->getItems() ?? [] as $argument) {
			if (!$argument instanceof ArgumentNode || $argument->ellipsis !== null) {
				return null;
			} elseif ($argument->name === null) {
				$positional++;
			} else {
				$named[] = $argument->name->text;
			}
		}

		$omitted = Parameter::findOmitted($parameters, $positional, $named);
		return $omitted === null ? null : ", but the attribute requires `\$$omitted->name`, which is not given";
	}


	/** Whether the declaration the attribute stands on carries an attribute of the class besides it. */
	private static function hasSibling(AttributeNode $node, string $class, RuleContext $context): bool
	{
		$groups = $node->parent?->parent?->parent;
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($groups instanceof PlainNodeList ? $groups->getItems() : [] as $group) {
			foreach ($group instanceof AttributeGroupNode ? $group->items->getItems() : [] as $other) {
				if ($other !== $node && strcasecmp($resolver->resolveClass($other->name), $class) === 0) {
					return true;
				}
			}
		}

		return false;
	}
}
