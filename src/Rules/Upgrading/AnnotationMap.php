<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\Analyses\PhpDoc;
use DressCode\{RuleContext, Values};
use DressCode\Rules\QualifiedNames;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind};
use PhpSyntax\Nodes\{FileNode, NameNode};
use PhpSyntax\Nodes\Member\{ClassConstNode, EnumCaseNode, MethodNode, PropertyNode};
use PhpSyntax\Nodes\Statement\{ClassNode, EnumNode, FunctionNode, InterfaceNode, NamespaceNode, TraitNode};
use function strlen;


/**
 * The map `attributeForAnnotation` as the project resolved it: the attribute written for an annotation by its name,
 * for the class of a Doctrine annotation or of an attribute, and for the classes of a namespace of annotations.
 * @internal
 */
final readonly class AnnotationMap
{
	public const Path = MemberMapGrammar::AttributeForAnnotation;

	/** The declarations whose doc comment holds the annotations. */
	public const Declarations = [
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

	/** The names not in lower case Doctrine ignores where no import makes them a class. */
	private const IgnoredAnnotations = [
		'Annotation', 'Attribute', 'Attributes', 'Required', 'Target', 'NamedArgumentConstructor', 'TODO', 'SuppressWarnings',
	];


	private function __construct(
		/** @var array<string, AttributeTarget>  lowercased annotation without @ => the attribute written instead */
		private array $attributes,
		/** @var array<string, AttributeTarget>  lowercased class of an attribute, fully qualified => the attribute written instead */
		private array $replacedAttributes,
		/** @var array<string, string>  lowercased namespace of annotations => the namespace of the attributes written instead */
		private array $namespaces,
	) {
	}


	public static function fromValues(Values $values): self
	{
		$attributes = $replacedAttributes = $namespaces = [];
		foreach ($values->readMap(self::Path) as $key => $code) {
			if (str_ends_with((string) $key, '*')) {
				if (($namespace = AttributeTarget::findNamespace($code)) !== null) {
					$namespaces[strtolower(trim((string) $key, '\\*'))] = $namespace;
				}
			} elseif (($target = AttributeTarget::fromCode($code)) === null) {
				continue;
			} elseif (str_contains((string) $key, '\\')) {
				$replacedAttributes[strtolower(ltrim((string) $key, '\\'))] = $target;
			} else {
				$attributes[strtolower(ltrim((string) $key, '@'))] = $target;
			}
		}

		return new self($attributes, $replacedAttributes, $namespaces);
	}


	public function isEmpty(): bool
	{
		return $this->attributes === [] && $this->replacedAttributes === [] && $this->namespaces === [];
	}


	/**
	 * The attribute the map writes for the annotation: by its name, `@cached`, or for a Doctrine annotation by
	 * its class, `@Validation\Size(...)` being the class the imports of the file make of the name, or by its namespace.
	 */
	public function findAttribute(PhpDocTagNode $tag, Node $at, RuleContext $context): ?AttributeTarget
	{
		return $this->attributes[strtolower(ltrim($tag->name, '@'))]
			?? $this->findAttributeOfClass(self::resolveAnnotation($tag->name, $at, $context));
	}


	/** The attribute the map writes for the Doctrine annotation of the class, fully qualified, by the class or its namespace. */
	public function findAttributeOfClass(?string $class): ?AttributeTarget
	{
		if ($class === null) {
			return null;
		}

		$namespace = QualifiedNames::extractNamespace($class);
		$target = $this->namespaces[strtolower($namespace)] ?? null;
		return $this->replacedAttributes[strtolower($class)]
			?? ($target === null ? null : new AttributeTarget($target . substr($class, strlen($namespace)), fromNamespace: true));
	}


	/** Whether the map writes an attribute for the annotation by its name, which is text after it and no Doctrine annotation. */
	public function hasAnnotation(PhpDocTagNode $tag): bool
	{
		return isset($this->attributes[strtolower(ltrim($tag->name, '@'))]);
	}


	/** The attribute written instead of the attribute of the class, fully qualified. */
	public function findReplacedAttribute(string $class): ?AttributeTarget
	{
		return $this->replacedAttributes[strtolower($class)] ?? null;
	}


	/**
	 * The classes, lowercased, the Doctrine annotations of the scope stand for that the map writes an attribute for,
	 * whose imports a rule rewriting imports leaves to the annotations until they are attributes.
	 * @return array<string, true>
	 */
	public function findAnnotatedClasses(FileNode|NamespaceNode $scope, RuleContext $context): array
	{
		if ($this->replacedAttributes === [] && $this->namespaces === []) {
			return [];
		}

		$classes = [];
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		foreach ($scope->find(Node::class, fn(Node $node) => array_any(self::Declarations, fn(string $class) => $node instanceof $class)) as $declaration) {
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


	/**
	 * The class the name of a Doctrine annotation stands for, as the imports of the file and its namespace make it.
	 * Null where no import makes the name a class and it is in lower case or one Doctrine ignores, a tag of phpDoc
	 * such as `@param` or `@internal`, or one of Doctrine itself such as `@Target`.
	 */
	public static function resolveAnnotation(string $name, Node $at, RuleContext $context): ?string
	{
		$name = ltrim($name, '@');
		if (str_starts_with($name, '\\')) {
			return substr($name, 1);
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$parts = explode('\\', $name, 2);
		$import = $resolver->getImports(SymbolKind::ClassLike, $at)[strtolower($parts[0])] ?? null;
		if ($import === null && (!ctype_upper($name[0]) || in_array($name, self::IgnoredAnnotations, true))) {
			return null;
		}

		$node = NameNode::tryFromText($name);
		if ($node !== null) {
			return $resolver->resolveClass($node, $at);
		}

		$base = $import ?? ltrim($resolver->getNamespace($at) . '\\' . $parts[0], '\\');
		return isset($parts[1]) ? "$base\\$parts[1]" : $base;
	}
}
