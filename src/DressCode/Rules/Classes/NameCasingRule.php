<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\Scope;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\StaticPropertyFetchNode;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\Member\ClassConstNode;
use PhpSyntax\Nodes\Member\EnumCaseNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Member\PropertyNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement\ClassNode;
use PhpSyntax\Nodes\Statement\ConstNode;
use PhpSyntax\Nodes\Statement\EnumNode;
use PhpSyntax\Nodes\Statement\FunctionNode;
use PhpSyntax\Nodes\Statement\InterfaceNode;
use PhpSyntax\Nodes\Statement\TraitNode;
use PhpSyntax\Token;
use function in_array, strlen;


/**
 * Declared names follow the case convention configured for their kind: classes, interfaces, traits and enums,
 * methods, functions, constants (in a class and outside it), enum cases, properties (promoted ones included)
 * and variables. A kind set to null is not checked. PascalCase asks for a lowercase letter somewhere, so that
 * UPPER_CASE does not pass, but lets a name of two letters such as IO through; a leading double underscore
 * of a method or a function, the mark of a magic method, is not part of the name. Nothing is renamed.
 */
#[RuleInfo(
	'dresscode/name-casing',
	Stage::Structure,
	description: 'Reports declared names that do not follow the case convention configured for their kind',
)]
final class NameCasingRule extends Rule implements ConfigurableRule
{
	private const Kinds = ['classes', 'methods', 'functions', 'constants', 'enumCases', 'properties', 'variables'];
	private const Cases = ['PascalCase', 'camelCase', 'UPPER_CASE', 'snake_case'];
	private const Patterns = [
		'PascalCase' => '~^(?=.*[a-z]|.{1,2}$)[A-Z][A-Za-z0-9]*$~',
		'camelCase' => '~^[a-z][A-Za-z0-9]*$~',
		'UPPER_CASE' => '~^[A-Z][A-Z0-9_]*$~',
		'snake_case' => '~^[a-z][a-z0-9_]*$~',
	];
	private const ReservedVariables = [
		'$this', '$GLOBALS', '$_SERVER', '$_GET', '$_POST', '$_FILES', '$_COOKIE', '$_SESSION', '$_REQUEST', '$_ENV',
	];

	/** @var array<string, ?string> */
	private array $cases = [];


	public static function getOptionsSchema(): Schema
	{
		$case = Expect::anyOf(...self::Cases)->nullable();
		return Expect::structure([
			'classes' => (clone $case)->description('Classes, interfaces, traits and enums'),
			'methods' => clone $case,
			'functions' => clone $case,
			'constants' => (clone $case)->description('Class constants and constants declared with const outside a class; a constant of an enum may also follow enumCases'),
			'enumCases' => clone $case,
			'properties' => (clone $case)->description('Declared properties, promoted constructor parameters included'),
			'variables' => (clone $case)->description('Variables and parameters, except $this and the superglobals; each name once per function'),
		]);
	}


	public function configure(array $options): void
	{
		foreach (self::Kinds as $kind) {
			$this->cases[$kind] = $options[$kind];
		}
	}


	public function getVisitedTypes(): array
	{
		return [
			ClassNode::class, InterfaceNode::class, TraitNode::class, EnumNode::class,
			MethodNode::class, FunctionNode::class, ClassConstNode::class, ConstNode::class, EnumCaseNode::class,
			PropertyNode::class, ParameterNode::class, VariableNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		match (true) {
			$node instanceof ClassNode, $node instanceof InterfaceNode, $node instanceof TraitNode, $node instanceof EnumNode
				=> $this->check('classes', $node->name, $node->name->token->text, $context),
			$node instanceof MethodNode => $this->check('methods', $node->name, self::stripMagic($node->name->token->text), $context),
			$node instanceof FunctionNode => $this->check('functions', $node->name, self::stripMagic($node->name->token->text), $context),
			$node instanceof ClassConstNode, $node instanceof ConstNode => $this->checkItems('constants', $node, $context),
			$node instanceof EnumCaseNode => $this->check('enumCases', $node->name, $node->name->token->text, $context),
			$node instanceof PropertyNode => $this->checkItems('properties', $node, $context),
			$node instanceof ParameterNode => $this->checkParameter($node, $context),
			$node instanceof VariableNode => $this->checkVariable($node, $context),
			default => null,
		};
	}


	/** A constant of an enum may follow the case of its cases as well. */
	private function checkItems(string $kind, ClassConstNode|ConstNode|PropertyNode $node, RuleContext $context): void
	{
		$alternative = $node instanceof ClassConstNode && $node->parent?->parent instanceof EnumNode ? $this->cases['enumCases'] : null;
		foreach ($node->items->getItems() as $item) {
			$token = $item->name instanceof Token ? $item->name : $item->name->token;
			$name = ltrim($token->text, '$');
			if ($alternative === null || !preg_match(self::Patterns[$alternative], $name)) {
				$this->check($kind, $item, $name, $context);
			}
		}
	}


	/** A promoted parameter declares a property; any parameter is a variable. */
	private function checkParameter(ParameterNode $node, RuleContext $context): void
	{
		$name = $node->var->name;
		if (!$name instanceof Token) {
			return;
		}

		if ($node->modifiers->isEmpty()) {
			$this->checkVariableOnce($node->var, $name->text, $context);
		} else {
			$this->check('properties', $node->var, ltrim($name->text, '$'), $context);
		}
	}


	private function checkVariable(VariableNode $node, RuleContext $context): void
	{
		$name = $node->name;
		if (
			!$name instanceof Token
			|| $node->dollar !== null
			|| $node->parent instanceof ParameterNode
			|| $node->parent instanceof StaticPropertyFetchNode
			|| in_array($name->text, self::ReservedVariables, strict: true)
		) {
			return;
		}

		$this->checkVariableOnce($node, $name->text, $context);
	}


	/** Each name once per function, parameters and uses together. */
	private function checkVariableOnce(VariableNode $node, string $text, RuleContext $context): void
	{
		$scope = $context->getAnalysis(Scope::class)->getFunction($node);
		$key = ($scope === null ? 0 : spl_object_id($scope)) . $text;
		$storage = $context->getStorage();
		if ($storage->has($key)) {
			return;
		}

		$storage->set($key, true);
		$this->check('variables', $node, ltrim($text, '$'), $context);
	}


	private function check(string $kind, Node $at, string $name, RuleContext $context): void
	{
		$case = $this->cases[$kind] ?? null;
		if ($case === null || preg_match(self::Patterns[$case], $name)) {
			return;
		}

		$what = match ($kind) {
			'classes' => 'class', 'methods' => 'method', 'functions' => 'function', 'constants' => 'constant',
			'enumCases' => 'enum case', 'properties' => 'property', default => 'variable',
		};
		$context->report($at, "The $what '$name' must be written in $case");
	}


	private static function stripMagic(string $name): string
	{
		return str_starts_with($name, '__') && strlen($name) > 2 ? substr($name, 2) : $name;
	}
}
