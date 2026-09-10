<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\Scope;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\VariableNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\Member;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Nodes\Statement;
use PhpSyntax\Token;
use function in_array;


/**
 * Declared names follow the case convention configured for their kind: classes, interfaces, traits and enums,
 * methods, functions, constants (in a class and outside it), enum cases, properties (promoted ones included)
 * and variables. A kind set to null is not checked. PascalCase asks for a lowercase letter somewhere, so that
 * UPPER_CASE does not pass, but lets a name of two letters such as IO through; a method or a function whose
 * name begins with a double underscore is PHP's and not checked. A name whose spelling the
 * code does not choose, such as a method of a stream wrapper, is left alone by a pattern in `ignorePatterns`, matched
 * against the name as it is reported, so without the `$` of a variable or a property. A variable or parameter
 * written with underscores alone (`$_`) is a placeholder saying the value is of no interest, not a name.
 * A declaration marked
 * deprecated is left alone: what carries the old name of something already renamed cannot be renamed again.
 * Nothing is renamed.
 */
#[RuleInfo(
	'dresscode/name-casing',
	Stage::Structure,
	description: 'Reports declared names that do not follow the case convention configured for their kind',
)]
final class NameCasingRule extends NodeRule implements ConfigurableRule
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
			'classes' => (clone $case)->description('Classes, interfaces, traits and enums'),
			'methods' => clone $case,
			'functions' => clone $case,
			'constants' => (clone $case)->description('Class constants and constants declared with const outside a class; a constant of an enum may also follow enumCases'),
			'enumCases' => clone $case,
			'properties' => (clone $case)->description('Declared properties, promoted constructor parameters included'),
			'variables' => (clone $case)->description('Variables and parameters, except $this and the superglobals; each name once per function'),
			'ignorePatterns' => Expect::listOf('string')
				->description('Regular expressions; a name matching one is never reported, whatever its kind, as the methods of a stream wrapper or a replacement for a native function are'),
		]);
	}


	public function configure(array $options): void
	{
		foreach (self::Kinds as $kind) {
			$this->cases[$kind] = $options[$kind] === 'keep' ? null : $options[$kind];
		}

		$this->ignorePatterns = $options['ignorePatterns'];
	}


	public function getVisitedTypes(): array
	{
		return [
			Statement\ClassNode::class, Statement\InterfaceNode::class, Statement\TraitNode::class, Statement\EnumNode::class,
			Member\MethodNode::class, Statement\FunctionNode::class, Member\ClassConstNode::class, Statement\ConstNode::class, Member\EnumCaseNode::class,
			Member\PropertyNode::class, ParameterNode::class, VariableNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			$node instanceof Node
			&& !$node instanceof ParameterNode
			&& !$node instanceof VariableNode
			&& NodeHelpers::isDeprecated($node, $context)
		) {
			return; // what is deprecated is usually the old name of something already renamed
		}

		match (true) {
			$node instanceof Statement\ClassNode, $node instanceof Statement\InterfaceNode, $node instanceof Statement\TraitNode, $node instanceof Statement\EnumNode
				=> $this->check('classes', $node->name, $node->name->token->text, $context),
			$node instanceof Member\MethodNode => $this->checkFunction('methods', $node->name, $context),
			$node instanceof Statement\FunctionNode => $this->checkFunction('functions', $node->name, $context),
			$node instanceof Member\ClassConstNode, $node instanceof Statement\ConstNode => $this->checkItems('constants', $node, $context),
			$node instanceof Member\EnumCaseNode => $this->check('enumCases', $node->name, $node->name->token->text, $context),
			$node instanceof Member\PropertyNode => $this->checkItems('properties', $node, $context),
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
		$alternative = $node instanceof Member\ClassConstNode && $node->parent?->parent instanceof Statement\EnumNode ? $this->cases['enumCases'] : null;
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
		$name = $node->variable->name;
		if (!$name instanceof Token) {
			return;
		}

		if ($node->modifiers->isEmpty()) {
			$this->checkVariableOnce($node->variable, $name->text, $context);
		} else {
			$this->check('properties', $node->variable, ltrim($name->text, '$'), $context);
		}
	}


	private function checkVariable(VariableNode $node, RuleContext $context): void
	{
		$name = $node->name;
		if (
			!$name instanceof Token
			|| $node->dollar !== null
			|| $node->parent instanceof ParameterNode
			|| in_array($name->text, self::ReservedVariables, strict: true)
		) {
			return;
		}

		$this->checkVariableOnce($node, $name->text, $context);
	}


	/** Each name once per function, parameters and uses together. */
	private function checkVariableOnce(VariableNode $node, string $text, RuleContext $context): void
	{
		if (trim($text, '$_') === '') {
			return; // $_ says the value is of no interest; underscores alone are a placeholder, not a name
		}

		$scope = $context->getAnalysis(Scope::class)->getFunction($node);
		$key = ($scope === null ? 0 : spl_object_id($scope)) . $text;
		if (isset($context->storage[$key])) {
			return;
		}

		$context->storage[$key] = true;
		$this->check('variables', $node, ltrim($text, '$'), $context);
	}


	private function check(string $kind, Node $at, string $name, RuleContext $context): void
	{
		$case = $this->cases[$kind] ?? null;
		if ($case === null || preg_match(self::Patterns[$case], $name) || $this->isExcepted($name)) {
			return;
		}

		$what = match ($kind) {
			'classes' => 'class', 'methods' => 'method', 'functions' => 'function', 'constants' => 'constant',
			'enumCases' => 'enum case', 'properties' => 'property', default => 'variable',
		};
		$context->report($at, "The $what '$name' must be written in $case");
	}


	private function isExcepted(string $name): bool
	{
		foreach ($this->ignorePatterns as $pattern) {
			if (preg_match($pattern, $name)) {
				return true;
			}
		}

		return false;
	}


	/** PHP reserves every name beginning with a double underscore for itself, so the project does not choose it. */
	private function checkFunction(string $kind, IdentifierNode $name, RuleContext $context): void
	{
		$text = $name->token->text;
		if (!str_starts_with($text, '__')) {
			$this->check($kind, $name, $text, $context);
		}
	}
}
