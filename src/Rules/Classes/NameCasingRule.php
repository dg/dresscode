<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Classes;

use DressCode\{Analyses, Decision, DecisionKind, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Analyses\PhpDoc;
use DressCode\Domains\{Names, Words};
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\{AttributeAwareNode, FunctionLikeNode, Member, ParameterNode, Statement};
use PhpSyntax\Nodes\Expression\VariableNode;


/**
 * Declared names follow the case convention configured for their kind: classes, interfaces, traits and enums,
 * methods, functions, constants (in a class and outside it), enum cases, properties (promoted ones included)
 * and variables. PascalCase asks for a lowercase letter somewhere, so that UPPER_CASE does not pass, but lets
 * a name of two letters such as IO through.
 *
 * A kind whose decision is `keep` is not checked. A method or a function whose name begins with a double
 * underscore is PHP's and not checked, and a variable or parameter written with underscores alone (`$_`) is
 * a placeholder saying the value is of no interest, not a name. A name whose spelling the code does not choose,
 * such as a method of a stream wrapper, is left alone by a pattern in `naming.except`, matched against the name
 * as it is reported, so without the `$` of a variable or a property. A declaration marked deprecated is left
 * alone: what carries the old name of something already renamed cannot be renamed again. Nothing is renamed.
 */
#[RuleInfo(Stage::Structure, analyses: [PhpDoc::class, NameResolver::class])]
final class NameCasingRule extends NodeRule
{
	private const Kinds = ['class', 'method', 'function', 'constant', 'enumCase', 'property', 'variable'];
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


	public static function getDecisions(): array
	{
		$cases = new Words([
			'PascalCase' => 'a capital first and a lowercase letter somewhere, `FooBar`, a name of two letters such as `IO` passing',
			'camelCase' => 'a lowercase letter first, `fooBar`',
			'UPPER_CASE' => 'capitals, digits and underscores, `FOO_BAR`',
			'snake_case' => 'lowercase letters, digits and underscores, `foo_bar`',
		]);
		$decisions = [];
		foreach ([
			'class' => 'a class, an interface, a trait and an enum',
			'method' => 'a method, those beginning with `__` being PHP\'s',
			'function' => 'a function, those beginning with `__` being PHP\'s',
			'constant' => 'a class constant and a `const` outside a class, a constant of an enum passing in this case or in that of `enumCase`',
			'enumCase' => 'a case of an enum',
			'property' => 'a property, a promoted constructor parameter included',
			'variable' => 'a variable and a parameter, `$this`, the superglobals and a placeholder such as `$_` excepted',
		] as $kind => $what) {
			$decisions[] = new Decision("naming.$kind", $cases, "The case of the name of $what, which is reported and never renamed");
		}

		$decisions[] = new Decision('naming.except', new Names(regularExpressions: true), 'The patterns of names never reported, whatever their kind, as the methods of a stream wrapper or a replacement for a native function', kind: DecisionKind::Parameter, default: []);
		return $decisions;
	}


	public function configure(Values $values): void
	{
		foreach (self::Kinds as $kind) {
			$value = $values->get("naming.$kind");
			$this->cases[$kind] = $value->isKept() ? null : $value->getWord();
		}

		$this->ignorePatterns = $values->get('naming.except')->getNames();
	}


	/** Only the nodes of the kinds given a case. */
	public function getVisitedNodes(): array
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
			$this->check('property', $node->variable, ltrim($name->text, '$'), $context, $node);
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
		$context->report($at, "The $what `$name` must be written in $case.", fixable: false, decision: "naming.$kind");
	}


	/**
	 * Whether the declaration says of itself that it is deprecated, by the `@deprecated` annotation or by the
	 * `#[\Deprecated]` attribute of PHP 8.4.
	 */
	private static function isDeprecated(Node $node, RuleContext $context): bool
	{
		$docComment = $node->getDocComment();
		if ($docComment !== null) {
			foreach ($context->getAnalysis(Analyses\PhpDoc::class)->parse($docComment)->children as $child) {
				if ($child instanceof PhpDocTagNode && strcasecmp($child->name, '@deprecated') === 0) {
					return true;
				}
			}
		}

		return $node instanceof AttributeAwareNode && $context->getAnalysis(NameResolver::class)->hasAttribute($node, \Deprecated::class);
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
