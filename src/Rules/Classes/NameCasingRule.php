<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Analyses, ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Context, Expect, Schema};
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AttributeGroupNode, FunctionLikeNode, Member, ParameterNode, PlainNodeList, Statement};
use PhpSyntax\Nodes\Expression\VariableNode;
use function in_array;


/**
 * Declared names follow the case convention configured for their kind: classes, interfaces, traits and enums,
 * methods, functions, constants (in a class and outside it), enum cases, properties (promoted ones included)
 * and variables. PascalCase asks for a lowercase letter somewhere, so that UPPER_CASE does not pass, but lets
 * a name of two letters such as IO through.
 *
 * A kind left out or set to keep is not checked. A method or a function whose name begins with a double
 * underscore is PHP's and not checked, and a variable or parameter written with underscores alone (`$_`) is
 * a placeholder saying the value is of no interest, not a name. A name whose spelling the code does not choose,
 * such as a method of a stream wrapper, is left alone by a pattern in `ignorePatterns`, matched against the name
 * as it is reported, so without the `$` of a variable or a property. A declaration marked deprecated is left
 * alone: what carries the old name of something already renamed cannot be renamed again. Nothing is renamed.
 */
#[RuleInfo(
	'dresscode/nameCasing',
	Stage::Structure,
	description: 'Reports declared names that do not follow the case convention configured for their kind',
)]
final class NameCasingRule extends NodeRule implements ConfigurableRule
{
	private const Kinds = ['class', 'method', 'function', 'constant', 'enumCase', 'property', 'variable'];
	private const Cases = ['PascalCase', 'camelCase', 'UPPER_CASE', 'snake_case'];
	private const Patterns = [
		'PascalCase' => '~^(?=.*[a-z]|.{1,2}$)[A-Z][A-Za-z0-9]*$~',
		'camelCase' => '~^[a-z][A-Za-z0-9]*$~',
		'UPPER_CASE' => '~^[A-Z][A-Z0-9_]*$~',
		'snake_case' => '~^[a-z][a-z0-9_]*$~',
	];
	private const ReservedVariables = [
		'$this', '$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV', '$argc', '$argv', '$http_response_header',
	];

	/** @var array<string, ?string> */
	private array $cases = [];

	/** @var list<string> */
	private array $ignorePatterns = [];


	public static function getOptionsSchema(): Schema
	{
		$case = Expect::anyOf(...[...self::Cases, 'keep']);
		return Expect::structure([
			'class' => (clone $case)->description('Classes, interfaces, traits and enums'),
			'method' => clone $case,
			'function' => clone $case,
			'constant' => (clone $case)->description('Class constants and constants declared with `const` outside a class; a constant of an enum may also follow `enumCase`'),
			'enumCase' => clone $case,
			'property' => (clone $case)->description('Declared properties, promoted constructor parameters included'),
			'variable' => (clone $case)->description('Variables and parameters, except `$this` and the superglobals; each name once per function'),
			'ignorePatterns' => Expect::listOf('string')
				->description('Regular expressions; a name matching one is never reported, whatever its kind, as the methods of a stream wrapper or a replacement for a native function are'),
		])->transform(function (mixed $options, Context $context): mixed {
			if (array_all(array_diff_key((array) $options, ['ignorePatterns' => true]), fn($case) => $case === null)) {
				$context->addWarning('No kind of name is given a case, so nothing is reported.', 'dresscode.noEffect');
			}

			return $options;
		});
	}


	public function configure(array $options): void
	{
		foreach (self::Kinds as $kind) {
			$this->cases[$kind] = $options[$kind] === 'keep' ? null : $options[$kind];
		}

		$this->ignorePatterns = $options['ignorePatterns'];
	}


	/** Only the nodes of the kinds given a case. */
	public function getVisitedTypes(): array
	{
		$has = fn(string $kind) => ($this->cases[$kind] ?? null) !== null;
		return [
			...($has('class') ? [Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class] : []),
			...($has('method') ? [Member\MethodNode::class] : []),
			...($has('function') ? [Statement\FunctionNode::class] : []),
			...($has('constant') ? [Member\ClassConstNode::class, Statement\ConstNode::class] : []),
			...($has('enumCase') ? [Member\EnumCaseNode::class] : []),
			...($has('property') ? [Member\PropertyNode::class] : []),
			...($has('property') || $has('variable') ? [ParameterNode::class] : []),
			...($has('variable') ? [VariableNode::class] : []),
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		match (true) {
			$node instanceof Statement\ClassNode, $node instanceof Statement\InterfaceNode, $node instanceof Statement\TraitNode, $node instanceof Statement\EnumNode
				=> $this->check('class', $node->name, $node->name->token->text, $context, $node),
			$node instanceof Member\MethodNode => $this->checkFunction('method', $node, $context),
			$node instanceof Statement\FunctionNode => $this->checkFunction('function', $node, $context),
			$node instanceof Member\ClassConstNode, $node instanceof Statement\ConstNode => $this->checkItems('constant', $node, $context),
			$node instanceof Member\EnumCaseNode => $this->check('enumCase', $node->name, $node->name->token->text, $context, $node),
			$node instanceof Member\PropertyNode => $this->checkItems('property', $node, $context),
			$node instanceof ParameterNode => $this->checkParameter($node, $context),
			$node instanceof VariableNode => $this->checkVariable($node, $context),
			default => null,
		};
	}


	/** A constant of an enum may follow the case of its cases as well. */
	private function checkItems(
		string $kind,
		Member\ClassConstNode|Statement\ConstNode|Member\PropertyNode $node,
		RuleContext $context,
	): void
	{
		$alternative = $node instanceof Member\ClassConstNode && $node->parent?->parent instanceof Statement\EnumNode ? $this->cases['enumCase'] : null;
		foreach ($node->items->getItems() as $item) {
			$token = $item instanceof Member\PropertyItemNode ? $item->name : $item->name->token;
			$name = ltrim($token->text, '$');
			if ($alternative === null || !preg_match(self::Patterns[$alternative], $name)) {
				$this->check($kind, $item, $name, $context, $node);
			}
		}
	}


	/** A promoted parameter declares a property; any other parameter is a variable. */
	private function checkParameter(ParameterNode $node, RuleContext $context): void
	{
		$name = $node->variable->name;
		if (!$name instanceof Token) {
			return;
		}

		if ($node->modifiers->isEmpty()) {
			$this->checkVariableOnce($node->variable, $name->text, $context);
		} else {
			$this->check('property', $node->variable, ltrim($name->text, '$'), $context);
		}
	}


	private function checkVariable(VariableNode $node, RuleContext $context): void
	{
		$name = $node->name;
		if (
			!$name instanceof Token
			|| $node->dollar !== null
			|| $node->parent instanceof ParameterNode
			|| in_array($name->text, self::ReservedVariables, true)
		) {
			return;
		}

		$this->checkVariableOnce($node, $name->text, $context);
	}


	/** Each name once per function, parameters and uses together. */
	private function checkVariableOnce(VariableNode $node, string $text, RuleContext $context): void
	{
		$case = $this->cases['variable'] ?? null;
		if (trim($text, '$_') === '' || $case === null || preg_match(self::Patterns[$case], ltrim($text, '$'))) {
			return;
		}

		$scope = $node->findAncestor(FunctionLikeNode::class);
		$key = ($scope === null ? 0 : spl_object_id($scope)) . $text;
		if (isset($context->storage[$key])) {
			return;
		}

		$context->storage[$key] = true;
		$this->check('variable', $node, ltrim($text, '$'), $context);
	}


	/** @param ?Node $declaration  the declaration, which is left alone when it is deprecated */
	private function check(string $kind, Node $at, string $name, RuleContext $context, ?Node $declaration = null): void
	{
		$case = $this->cases[$kind] ?? null;
		if (
			$case === null
			|| preg_match(self::Patterns[$case], $name)
			|| $this->isExcepted($name)
			|| ($declaration !== null && self::isDeprecated($declaration, $context))
		) {
			return;
		}

		$what = match ($kind) {
			'class' => 'class', 'method' => 'method', 'function' => 'function', 'constant' => 'constant',
			'enumCase' => 'enum case', 'property' => 'property', default => 'variable',
		};
		$context->report($at, "The $what `$name` must be written in $case", fixable: false);
	}


	/**
	 * Whether the declaration says of itself that it is deprecated, by the `@deprecated` annotation or by the
	 * `#[\Deprecated]` attribute of PHP 8.4.
	 */
	private static function isDeprecated(Node $node, RuleContext $context): bool
	{
		$docComment = $node->getDocComment();
		if ($docComment !== null && !$docComment->inInterpolation) {
			foreach ($context->getAnalysis(Analyses\PhpDoc::class)->parse($docComment)->children as $child) {
				if ($child instanceof PhpDocTagNode && strcasecmp($child->name, '@deprecated') === 0) {
					return true;
				}
			}
		}

		$attributes = property_exists($node, 'attributes') ? $node->attributes : null;
		$resolver = $context->getAnalysis(NameResolver::class);
		foreach ($attributes instanceof PlainNodeList ? $attributes->getItems() : [] as $group) {
			foreach ($group instanceof AttributeGroupNode ? $group->items->getItems() : [] as $attribute) {
				if (strcasecmp($resolver->resolveClass($attribute->name, $node), 'Deprecated') === 0) {
					return true;
				}
			}
		}

		return false;
	}


	private function isExcepted(string $name): bool
	{
		return array_any($this->ignorePatterns, fn(string $pattern) => preg_match($pattern, $name) === 1);
	}


	/** Leaves out the names PHP reserves. */
	private function checkFunction(string $kind, Member\MethodNode|Statement\FunctionNode $node, RuleContext $context): void
	{
		$text = $node->name->token->text;
		if (!str_starts_with($text, '__')) {
			$this->check($kind, $node->name, $text, $context, $node);
		}
	}
}
