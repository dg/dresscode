<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Access;
use DressCode\Analyses\MemberKind;
use DressCode\Analyses\Parameter;
use DressCode\Analyses\Types;
use DressCode\ConfigurableRule;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentListNode;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\ClassConstantFetchNode;
use PhpSyntax\Nodes\Expression\ConstantFetchNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Expression\ParenthesizedNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\Scalar\IntegerNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count, is_bool;


/**
 * A tool for a method that takes named arguments where it took an integer of flags: the project, or a library it
 * stands on, maps a call to what each flag becomes, and the rule writes `Json::encode($value, pretty: true,
 * asciiSafe: true)` for `Json::encode($value, Json::PRETTY | Json::ESCAPE_UNICODE)`. Whose method a call reaches
 * is decided by the type of what it is made on, as replaced-calls decides it.
 *
 * A key is a method or an instantiation with the shape of its arguments, `Class::name($value, $flags)`, the last
 * item of which, or the last before `...`, is the placeholder of the flags; the value maps each flag, `Class::NAME` or a global `NAME`, to the
 * named arguments it becomes, `{pretty: true}`, or to none, `{}`, where the method now does always what the flag
 * asked for. The flags are the constants joined by `|`, and `0` is none; a call
 * whose flags are anything else is reported and left alone, and so is one with a positional argument behind them,
 * which would lose its position, and one that already names an argument a flag becomes. An argument passed there
 * by its name, or as true or false, is the call the method takes now, and is left alone.
 */
#[RuleInfo(
	'dresscode/named-arguments-for-flags',
	Stage::Structure,
	description: 'Passes the named arguments a method takes instead of the flags of an integer',
	group: Group::Deprecations,
	requiresTypes: true,
)]
final class NamedArgumentsForFlagsRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, list<array{MemberPattern, array{string, array<string, array<string, scalar|null>>}}>>  lowercased name → the entries of that name with the placeholder of the flags and the arguments of each flag */
	private array $byName = [];


	public static function getOptionsSchema(): Schema
	{
		return MemberMaps::map(
			Expect::arrayOf(Expect::arrayOf(Expect::scalar()->nullable(), Expect::string()), Expect::string()),
			'The call, `Class::name($value, $flags)` with the placeholder of the flags last → the flag, `Class::NAME` or `NAME` → the named arguments it becomes, `{pretty: true}`',
			self::readFlags(...),
		);
	}


	public function configure(array $options): void
	{
		$this->byName = MemberMaps::read($options, self::readFlags(...));
	}


	public function getVisitedTypes(): array
	{
		return [MethodCallNode::class, StaticMethodCallNode::class, NewNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof MethodCallNode && !$node instanceof StaticMethodCallNode && !$node instanceof NewNode) {
			return;
		}

		// the types are asked only about a name the map knows
		$name = match (true) {
			$node instanceof NewNode => '__construct',
			$node->name instanceof IdentifierNode => $node->name->text,
			default => null,
		};
		$entries = $name === null ? [] : $this->byName[strtolower($name)] ?? [];
		$arguments = $node->arguments ?? ArgumentListNode::of();
		$types = $entries === [] ? null : $context->getAnalysis(Types::class);
		$access = $types?->findAccess($node);
		if ($types === null || $access === null) {
			return;
		}

		foreach ($entries as [$pattern, [$placeholder, $flags]]) {
			$bindings = $pattern->matches($access, $types)
				? $pattern->arguments?->bind($arguments, $types->findParameters($access))
				: null;
			$flagsArgument = $bindings?->arguments[$placeholder] ?? null;
			if ($flagsArgument instanceof ArgumentNode && $flagsArgument->name === null && !$flagsArgument->value->hasValue()) {
				$this->rewrite($node, $access, $pattern, $flags, $flagsArgument, $arguments, $types->findParameters($access), $context);
				return;
			}
		}
	}


	/**
	 * @param  array<string, array<string, scalar|null>>  $flags
	 * @param  ?list<Parameter>  $parameters  of the method called, which name the arguments standing behind the flags
	 */
	private function rewrite(
		MethodCallNode|StaticMethodCallNode|NewNode $node,
		Access $access,
		MemberPattern $pattern,
		array $flags,
		ArgumentNode $flagsArgument,
		ArgumentListNode $arguments,
		?array $parameters,
		RuleContext $context,
	): void
	{
		$named = $unknown = [];
		foreach (self::splitFlags($flagsArgument->value) as $operand) {
			$flag = array_find(
				self::resolveFlag($operand, $context->getAnalysis(NameResolver::class), $context->getAnalysis(Types::class)),
				fn(string $flag) => isset($flags[$flag]),
			);
			if ($flag === null) {
				$unknown[] = $operand;
			} else {
				$named = array_replace($named, $flags[$flag]);
			}
		}

		// an argument behind the flags by its position loses it, so it takes the name of its parameter
		$items = $arguments->items->getItems();
		$index = (int) array_search($flagsArgument, $items, strict: true);
		$behind = [];
		foreach (array_slice($items, $index + 1, preserve_keys: true) as $position => $item) {
			if ($item instanceof ArgumentNode && $item->name === null) {
				$behind[] = [$item, $parameters[$position]->name ?? null];
			}
		}

		$names = [...array_keys($named), ...array_column($behind, 1)];
		$refusal = match (true) {
			$unknown !== [] => ", but `{$unknown[0]->text}` is no flag the map names",
			array_any($behind, fn(array $argument) => $argument[1] === null || $argument[0]->ellipsis !== null) => ', but an argument stands behind the flags by its position and the method gives it no name',
			array_any($items, fn(Node $item) => $item instanceof ArgumentNode && $item !== $flagsArgument && in_array($item->name?->text, $names, true))
			|| count($names) !== count(array_unique($names)) => ', but the call names an argument a flag becomes already',
			default => null,
		};

		$written = [];
		foreach ($named as $name => $value) {
			$written[] = "$name: " . ($value === null || is_bool($value) ? strtolower(var_export($value, true)) : var_export($value, true));
		}

		$takes = match (true) {
			$unknown !== [] => 'named arguments',
			$written === [] => 'no argument',
			default => implode(', ', $written),
		};

		// parent::name() is written as a static call and says nothing of the method being one
		$kind = $node instanceof StaticMethodCallNode && $node->class instanceof NameNode && $node->class->isSpecialClass()
			? MemberKind::Method
			: $access->kind;
		if (
			!$context->report(
				$flagsArgument,
				$pattern->describe($kind) . " takes $takes instead of `{$flagsArgument->value->text}`" . ($refusal ?? ''),
				fixable: $refusal === null,
			)
			|| $refusal !== null
		) {
			return;
		}

		foreach ($behind as [$argument, $name]) {
			$call = (new Parser)->parseExpression("f($name: 0)");
			assert($call instanceof FunctionCallNode && $call->arguments->items->getItems()[0] instanceof ArgumentNode);
			$renamed = $call->arguments->items->getItems()[0]->withoutEdgeTrivia();
			$renamed->value = $argument->value->withoutEdgeTrivia();
			$argument->replaceWith($renamed);
		}

		$call = (new Parser)->parseExpression('f(' . implode(', ', $written) . ')');
		assert($call instanceof FunctionCallNode);
		$new = array_map(fn(Node $item) => $item->withoutEdgeTrivia(), $call->arguments->items->getItems());
		if ($new === []) {
			$arguments->items->removeItem($flagsArgument);
			return;
		}

		$flagsArgument->replaceWith($new[0]);
		foreach (array_slice($new, 1) as $offset => $argument) {
			$arguments->items->insert($index + 1 + $offset, $argument);
		}
	}


	/**
	 * The operands of the flags joined by `|`, without the parentheses around them and without the zeros.
	 * @return list<ExpressionNode>
	 */
	private static function splitFlags(ExpressionNode $flags): array
	{
		return match (true) {
			$flags instanceof ParenthesizedNode => self::splitFlags($flags->expression),
			$flags instanceof BinaryOpNode && $flags->operator->text === '|' => [...self::splitFlags($flags->left), ...self::splitFlags($flags->right)],
			$flags instanceof IntegerNode && $flags->toValue() === 0 => [],
			default => [$flags],
		};
	}


	/**
	 * The flag the operand names, fully qualified the way the map keys it: a constant of a class by the class that
	 * declares it, `$router::ONE_WAY` and `self::ONE_WAY` alike, and by the class written; none for what is no constant.
	 * @return list<string>
	 */
	private static function resolveFlag(ExpressionNode $operand, NameResolver $resolver, Types $types): array
	{
		if ($operand instanceof ConstantFetchNode) {
			return [$resolver->resolveConstant($operand->name)];
		} elseif (!$operand instanceof ClassConstantFetchNode || !$operand->name instanceof IdentifierNode) {
			return [];
		}

		$classes = [
			$types->findCallee($operand)?->declaringClass,
			$operand->class instanceof NameNode && !$operand->class->isSpecialClass() ? $resolver->resolveClass($operand->class) : null,
		];
		return array_values(array_unique(array_map(
			fn(string $class) => strtolower($class) . '::' . $operand->name->text,
			array_filter($classes),
		)));
	}


	/**
	 * The placeholder of the flags, and the arguments of each flag keyed by the flag written fully qualified, the class
	 * in lower case.
	 * @param  array<string, array<string, scalar|null>>  $value
	 * @return array{string, array<string, array<string, scalar|null>>}
	 * @throws \InvalidArgumentException
	 */
	private static function readFlags(array $value, MemberPattern $key): array
	{
		$items = $key->arguments->items ?? [];
		if ($items !== [] && $items[count($items) - 1]->variadic && $items[count($items) - 1]->placeholder === null) {
			array_pop($items); // the flags may stand before `...`
		}

		$last = $items === [] ? null : $items[count($items) - 1];
		if ($last === null || $last->placeholder === null || $last->variadic || $last->parameterName !== null) {
			throw new \InvalidArgumentException("The call $key->class::$key->name has to be given with the placeholder of its flags last, $key->class::$key->name(\$value, \$flags) or $key->class::$key->name(\$value, \$flags, ...).");
		}

		$flags = [];
		foreach ($value as $flag => $arguments) {
			if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)(?:::(\w+))?$~D', (string) $flag, $m, PREG_UNMATCHED_AS_NULL)) {
				throw new \InvalidArgumentException("The flag '$flag' of $key->class::$key->name is not written as Class::NAME or NAME.");
			}

			$flags[$m[2] === null ? $m[1] : strtolower($m[1]) . "::$m[2]"] = $arguments;
		}

		return [$last->placeholder, $flags];
	}
}
