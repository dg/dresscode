<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\{Access, MemberKind, PhpDoc, Types};
use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\CodeWriter;
use DressCode\Rules\PhpDoc\AnnotationToAttribute;
use Nette\Schema\{Context, Expect, Schema};
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\{GenericTagValueNode, PhpDocTagNode, PhpDocTextNode};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\{ArgumentNode, AttributeGroupNode, AttributeNode, FileNode, NameNode, NodeList};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, FunctionNode, InterfaceNode, NamespaceNode, TraitNode};
use function strlen;


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
 * gives some, or on a declaration that carries the other attribute already, is reported and left as it is. The key stands for the Doctrine annotation of the class as well, `@Endpoint("/blog",
 * name="blog")` where the imports make `Endpoint` the class, whose arguments the attribute takes over
 * (AnnotationArguments), nested annotations becoming instantiations. Arguments of a shape PHP cannot write, and
 * arguments where the map gives the attribute its own, are reported.
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
	'dresscode/attribute-for-annotation',
	Stage::Structure,
	description: 'Writes the attribute a project or its libraries read instead of an annotation',
	modifiesComments: true,
)]
final class AttributeForAnnotationRule extends NodeRule implements ConfigurableRule
{
	private const AttributePattern = '~^\\\\?(\w+(?:\\\\\w+)*)(\(.*\))?$~Ds';

	private const NamespacePattern = '~^\\\\?(\w+(?:\\\\\w+)*)\\\\\*$~D';

	/** @var array<string, array{string, string}>  lowercased annotation without @ => the class of the attribute and what follows it */
	private array $attributes = [];

	/** @var array<string, array{string, string}>  lowercased class of an attribute, fully qualified => the class of the attribute written instead and what follows it */
	private array $replacedAttributes = [];

	/** @var array<string, string>  lowercased namespace of annotations => the namespace of the attributes written instead */
	private array $namespaces = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::arrayOf(MemberMaps::code(), Expect::string()->pattern('@?[\w-]+|\\\\?\w+(?:\\\\\w+)+|\\\\\w+|\\\\?\w+(?:\\\\\w+)*\\\\\*'))
			->description('The annotation, without the @, the class of an attribute, fully qualified, or a namespace of annotations, `Acme\Validation\*` → the attribute written instead, its class fully qualified, with its arguments where it has any, or the namespace of the attributes, `Acme\Validation\*`')
			->transform(function (array $options, Context $context): array {
				foreach ($options as $key => $code) {
					if (str_ends_with((string) $key, '*')) {
						if ($code !== MemberMaps::Keep && !preg_match(self::NamespacePattern, $code)) {
							$context->addError("The namespace $key is written instead as '$code', which is not a namespace ending with \\*.", 'dresscode.attributeCode');
						}
					} elseif ($code !== MemberMaps::Keep && !preg_match(self::AttributePattern, $code)) {
						$old = str_contains((string) $key, '\\') ? '#[' . ltrim((string) $key, '\\') . ']' : '@' . ltrim((string) $key, '@');
						$context->addError("The attribute '$code' written instead of $old is not a class with its arguments, Class or Class(arguments).", 'dresscode.attributeCode');
					}
				}

				return $options;
			});
	}


	public function configure(array $options): void
	{
		$this->attributes = $this->replacedAttributes = $this->namespaces = [];
		foreach ($options as $key => $code) {
			if ($code === MemberMaps::Keep) { // an entry a later layer withdrew
				continue;
			} elseif (str_ends_with((string) $key, '*')) {
				if (preg_match(self::NamespacePattern, $code, $m)) {
					$this->namespaces[strtolower(trim((string) $key, '\\*'))] = $m[1];
				}
			} elseif (!preg_match(self::AttributePattern, $code, $m)) {
				continue;
			} elseif (str_contains((string) $key, '\\')) {
				$this->replacedAttributes[strtolower(ltrim((string) $key, '\\'))] = [$m[1], $m[2] ?? ''];
			} else {
				$this->attributes[strtolower(ltrim((string) $key, '@'))] = [$m[1], $m[2] ?? ''];
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
			AttributeNode::class,
		];
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

		$docComment = $this->attributes === [] && $this->replacedAttributes === [] && $this->namespaces === [] ? null : $node->getDocComment();
		if ($docComment === null || $docComment->inInterpolation) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$kept = $codes = [];
		foreach ($tree->children as $child) {
			$attribute = $child instanceof PhpDocTagNode ? $this->findAttribute($child, $node, $context) : null;
			if ($attribute === null || AnnotationToAttribute::has($node->attributes, $attribute[0])) {
				$kept[] = $child;
				continue;
			}

			[$arguments, $refusal] = $this->writeArguments($child, $attribute, $node, $context);
			$types = $context->findAnalysis(Types::class);
			$refusal ??= $types === null || !$attribute[2] ? null : match ($types->isAttributeClass($attribute[0])) { // a namespace says nothing of its classes
				null => ", but $attribute[0] does not exist",
				false => ", but $attribute[0] is no attribute",
				true => null,
			};
			$refusal ??= self::findMissingArgument($attribute[0], $arguments, $types);
			$message = "Annotation $child->name is replaced by the attribute #[$attribute[0]$attribute[1]]";
			if ($refusal !== null) {
				$context->report($node, $message . $refusal, trivia: $docComment, fixable: false);
				$kept[] = $child;
			} elseif ($context->report($node, $message, trivia: $docComment)) {
				$codes[] = CodeWriter::spellClass($attribute[0], $node, $context) . $arguments;
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
			AnnotationToAttribute::apply($node, $node->attributes, $docComment, $tree, $codes, $phpDoc, $context);
		}
	}


	/**
	 * The attribute the map writes for the annotation: by its name, `@cached`, or for a Doctrine annotation by
	 * its class, `@Validation\Size(...)` being the class the imports of the file make of the name, or by its namespace.
	 * @return ?array{string, string, bool}  the class, what follows it, and whether a namespace gave it
	 */
	private function findAttribute(PhpDocTagNode $tag, Node $at, RuleContext $context): ?array
	{
		$attribute = $this->attributes[strtolower(ltrim($tag->name, '@'))] ?? null;
		return $attribute === null
			? $this->findAttributeOfClass(self::resolveAnnotation($tag->name, $at, $context))
			: [...$attribute, false];
	}


	/** @return ?array{string, string, bool}  the class, what follows it, and whether a namespace gave it */
	private function findAttributeOfClass(string $class): ?array
	{
		$attribute = $this->replacedAttributes[strtolower($class)] ?? null;
		$namespace = substr($class, 0, max(0, (int) strrpos($class, '\\')));
		$target = $this->namespaces[strtolower($namespace)] ?? null;
		return match (true) {
			$attribute !== null => [...$attribute, false],
			$target !== null => [$target . substr($class, strlen($namespace)), '', true],
			default => null,
		};
	}


	/**
	 * What follows the class of the attribute, its arguments with their parentheses, or why the annotation has none
	 * to write: a Doctrine annotation gives its arguments, where the map writes none, and nothing may follow it.
	 * @param  array{string, string, bool}  $attribute
	 * @return array{string, ?string}  the code and the refusal, a clause of the message
	 */
	private function writeArguments(PhpDocTagNode $tag, array $attribute, Node $at, RuleContext $context): array
	{
		// only the annotation of a class is a Doctrine one, whose arguments an attribute can take; after a name it is text
		if (!$tag->value instanceof DoctrineTagValueNode || isset($this->attributes[strtolower(ltrim($tag->name, '@'))])) {
			$text = $tag->value instanceof GenericTagValueNode ? trim($tag->value->value) : trim((string) $tag->value);
			return [$attribute[1], $text === '' ? null : ', but the text after it has no place there'];
		}

		$arguments = AnnotationArguments::write($tag->value->annotation, function (string $name) use ($at, $context): string {
			$replaced = $this->findAttributeOfClass(self::resolveAnnotation($name, $at, $context));
			return $replaced === null ? $name : CodeWriter::spellClass($replaced[0], $at, $context);
		});
		return match (true) {
			trim($tag->value->description) !== '' => [$attribute[1], ', but the text after it has no place there'],
			$arguments === '' => [$attribute[1], null],
			$arguments === null => ['', ', but its arguments are not what an attribute can be given'],
			$attribute[1] !== '' => ['', ', but the attribute has arguments of its own'],
			default => ["($arguments)", null],
		};
	}


	/** The class the name of a Doctrine annotation stands for, as the imports of the file and its namespace make it. */
	private static function resolveAnnotation(string $name, Node $at, RuleContext $context): string
	{
		$name = ltrim($name, '@');
		if (str_starts_with($name, '\\')) {
			return substr($name, 1);
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$parts = explode('\\', $name, 2);
		$base = $resolver->getClassImports($at)[strtolower($parts[0])] ?? ltrim($resolver->getNamespace($at) . '\\' . $parts[0], '\\');
		return isset($parts[1]) ? "$base\\$parts[1]" : $base;
	}


	private function enterAttribute(AttributeNode $node, RuleContext $context): void
	{
		$class = $this->replacedAttributes === [] ? null : $context->getAnalysis(NameResolver::class)->resolveClass($node->name);
		$attribute = $class === null ? null : $this->replacedAttributes[strtolower($class)] ?? null;
		if ($attribute === null || (strcasecmp($attribute[0], $class) === 0 && $attribute[1] === '')) {
			return;
		}

		$arguments = ($node->arguments?->items->getItems() ?? []) === [] ? '' : (string) $node->arguments?->text;
		$refusal = match (true) {
			$arguments !== '' && $attribute[1] !== '' => ', but the attribute written instead has arguments of its own',
			self::hasSibling($node, $attribute[0], $context) => ', but the declaration carries that attribute already',
			default => self::findMissingArgument($attribute[0], $arguments === '' ? $attribute[1] : $arguments, $context->findAnalysis(Types::class)),
		};
		if ($context->report($node, "Attribute #[$class] is replaced by #[$attribute[0]$attribute[1]]" . ($refusal ?? ''), fixable: $refusal === null)) {
			// the arguments go over as they are, the library reading them the same way
			$code = CodeWriter::spellClass($attribute[0], $node, $context) . ($arguments === '' ? $attribute[1] : $arguments);
			$group = (new Parser)->parseFragment(AttributeGroupNode::class, "#[$code]");
			$node->replaceWith($group->attributes->getItems()[0]->withoutEdgeTrivia());
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
		$parameters = $types?->findParameters(new Access(MemberKind::Constructor, '__construct', [$class], true));
		if ($parameters === null) {
			return null;
		}

		$group = (new Parser)->parseFragment(AttributeGroupNode::class, "#[Attribute$arguments]");
		$positional = 0;
		$named = [];
		foreach ($group->attributes->getItems()[0]->arguments?->items->getItems() ?? [] as $argument) {
			if (!$argument instanceof ArgumentNode || $argument->ellipsis !== null) {
				return null;
			} elseif ($argument->name === null) {
				$positional++;
			} else {
				$named[strtolower($argument->name->text)] = true;
			}
		}

		foreach ($parameters as $position => $parameter) {
			if (!$parameter->optional && $position >= $positional && !isset($named[strtolower($parameter->name)])) {
				return ", but the attribute requires \$$parameter->name, which is not given";
			}
		}

		return null;
	}


	/** Whether the declaration the attribute stands on carries an attribute of the class besides it. */
	private static function hasSibling(AttributeNode $node, string $class, RuleContext $context): bool
	{
		$groups = $node->parent?->parent?->parent;
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($groups instanceof NodeList ? $groups->getItems() : [] as $group) {
			foreach ($group instanceof AttributeGroupNode ? $group->attributes->getItems() : [] as $other) {
				if ($other !== $node && strcasecmp($resolver->resolveClass($other->name), $class) === 0) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * The classes, lowercased, the Doctrine annotations of the scope stand for that the map writes an attribute for,
	 * which is what a rule rewriting imports asks to leave their imports to the annotations until they are attributes.
	 * @return array<string, true>
	 */
	public function findAnnotatedClasses(FileNode|NamespaceNode $scope, RuleContext $context): array
	{
		if ($this->replacedAttributes === [] && $this->namespaces === []) {
			return [];
		}

		$classes = [];
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$declarations = array_filter(
			$this->getVisitedTypes(),
			fn(string $type) => $type !== AttributeNode::class,
		);
		foreach ($scope->find(Node::class, fn(Node $node) => array_any($declarations, fn(string $type) => $node instanceof $type)) as $declaration) {
			$docComment = $declaration->getDocComment();
			if ($docComment === null || $docComment->inInterpolation || !str_contains($docComment->text, '@')) {
				continue;
			}

			foreach ($phpDoc->parse($docComment)->children as $child) {
				$class = $child instanceof PhpDocTagNode ? self::resolveAnnotation($child->name, $declaration, $context) : null;
				if ($class !== null && $this->findAttributeOfClass($class) !== null) {
					$classes[strtolower($class)] = true;
				}
			}
		}

		return $classes;
	}


	/** Whether the name is that of an attribute the map writes another one instead of, which is what a rule reading the deprecations asks to stay silent. */
	public function knowsAttribute(NameNode $name, RuleContext $context): bool
	{
		return $name->parent instanceof AttributeNode
			&& isset($this->replacedAttributes[strtolower($context->getAnalysis(NameResolver::class)->resolveClass($name))]);
	}
}
