<?php declare(strict_types=1);

namespace DressCode\Rules\Classes;

use DressCode\Analyses\Access;
use DressCode\Analyses\MemberKind;
use DressCode\Analyses\Types;
use DressCode\ConfigurableRule;
use DressCode\Group;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Rules\NodeHelpers;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentListNode;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\ArrayItemNode;
use PhpSyntax\Nodes\Expression\ArrayAccessNode;
use PhpSyntax\Nodes\Expression\ArrayNode;
use PhpSyntax\Nodes\Expression\AssignmentByReferenceNode;
use PhpSyntax\Nodes\Expression\AssignmentNode;
use PhpSyntax\Nodes\Expression\BinaryOpNode;
use PhpSyntax\Nodes\Expression\CombinedAssignmentNode;
use PhpSyntax\Nodes\Expression\EmptyNode;
use PhpSyntax\Nodes\Expression\IssetNode;
use PhpSyntax\Nodes\Expression\ListNode;
use PhpSyntax\Nodes\Expression\MethodCallNode;
use PhpSyntax\Nodes\Expression\NewNode;
use PhpSyntax\Nodes\Expression\PostfixOpNode;
use PhpSyntax\Nodes\Expression\PrefixOpNode;
use PhpSyntax\Nodes\Expression\PropertyFetchNode;
use PhpSyntax\Nodes\Expression\StaticMethodCallNode;
use PhpSyntax\Nodes\ExpressionNode;
use PhpSyntax\Nodes\IdentifierNode;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Nodes\SeparatedNodeList;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Nodes\Statement\ForeachNode;
use PhpSyntax\Nodes\Statement\UnsetNode;
use PhpSyntax\Parser;
use PhpSyntax\Token;
use function count, is_array;


/**
 * A tool for a member that is used differently now: the project, or a library it stands on, maps a use of a member
 * to the expression written instead, and the rule rewrites every use of that shape. Whose member a use reaches is
 * decided by the type of what it is made on, as replaced-members decides it.
 *
 * A key is a method with the shape of its arguments, `Class::name($a, true)`, or an instantiation,
 * `Class::__construct($a)`: `$name` stands for any expression, a literal for the same value, `name: $x` for an
 * argument passed by that name, `...` and `...$rest` for the arguments left, so `Class::name(...$args)` is a call with
 * any arguments and `Class::name()` one without any. A positional item takes the argument passed by the name of its parameter too, which the declaration of
 * the method gives; a call that fits no key is left alone, and of the keys it fits the most specific one decides.
 * The value is the expression written instead, with the placeholders of the key: a call of a bare name is a call on
 * what the replaced call was made on, `addMultiUpload($name, $label)`, a qualified name a function or a class of its
 * own, `\Closure::fromCallable($callable)`, and an operator is written as one, `getOption($key) ?? $default`.
 *
 * A property is a key too, `Class::$name`, with the expression a read becomes and the one an assignment does,
 * `{get: isFilled(), set: setFilled($value)}`. And a magic method is a key for the syntax PHP calls it by:
 * `__get($name)` is a read of a property no class declares, `__set($name, $value)` an assignment to one, `__isset()`
 * and `__unset()` what their names say, and `offsetGet($key)`, `offsetSet($key, $value)`, `offsetExists()` and
 * `offsetUnset()` the same for `$object[$key]`, `offsetSet(null, $value)` being `$object[] = $value`. An assignment
 * is rewritten where it is a statement, its value being otherwise used, `isset()` and `unset()` where they hold
 * nothing else, and what writes the property any other way is reported and left as it is; a read on the left of
 * `??` is risky, PHP asking there whether the property is set before it reads it. That a function takes its argument
 * by reference is not seen, so a property passed to one is read as any other.
 *
 * The fix is not risky where every argument is evaluated once, as before. Where the expression written instead
 * evaluates one only sometimes, not at all, or two of them in another order, the use is risky unless evaluating
 * the argument does nothing; where it would evaluate one twice, the use is reported and left as it is. A method
 * a child declares under the name of a key that takes any arguments is reported too: the method is replaced as
 * a whole there, and a declaration is nothing an expression could stand for.
 */
#[RuleInfo(
	'dresscode/replaced-calls',
	Stage::Structure,
	description: 'Writes a call, an access or an instantiation the way a project or its libraries write it instead',
	group: Group::Deprecations,
	requiresTypes: true,
)]
final class ReplacedCallsRule extends NodeRule implements ConfigurableRule
{
	/** @var array<string, list<array{MemberPattern, CallTemplate|PropertyTemplates}>>  lowercased name → the entries of that name, the most specific first */
	private array $byName = [];


	public static function getOptionsSchema(): Schema
	{
		return MemberMaps::map(
			Expect::anyOf(
				MemberMaps::code(),
				Expect::structure(['get' => MemberMaps::code()->nullable(), 'set' => MemberMaps::code()->nullable()])->castTo('array'),
			),
			'The replaced use, `Class::name($a, true)`, `Class::name(...$args)` with any arguments, `Class::name()` without any, `Class::__construct($a)` or a magic method for the syntax PHP calls it by → the expression written instead, with the placeholders of the key; `Class::$name` → `{get: expression, set: expression with $value}`',
			self::createTemplate(...),
		);
	}


	public function configure(array $options): void
	{
		$this->byName = MemberMaps::read($options, self::createTemplate(...));
		foreach ($this->byName as &$entries) {
			usort($entries, fn(array $a, array $b) => ($a[0]->arguments ?? ArgumentPattern::parse('...'))
				->compareSpecificity($b[0]->arguments ?? ArgumentPattern::parse('...')));
		}
	}


	public function getVisitedTypes(): array
	{
		return [
			MethodCallNode::class,
			StaticMethodCallNode::class,
			NewNode::class,
			PropertyFetchNode::class,
			ArrayAccessNode::class,
			AssignmentNode::class,
			IssetNode::class,
			UnsetNode::class,
			MethodNode::class,
		];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		match (true) {
			$node instanceof MethodCallNode, $node instanceof StaticMethodCallNode, $node instanceof NewNode => $this->enterCall($node, $context),
			$node instanceof PropertyFetchNode, $node instanceof ArrayAccessNode => $this->enterAccess($node, $context),
			$node instanceof AssignmentNode => $this->enterAssignment($node, $context),
			$node instanceof IssetNode, $node instanceof UnsetNode => $this->enterIssetOrUnset($node, $context),
			$node instanceof MethodNode => $this->enterDeclaration($node, $context),
			default => null,
		};
	}


	/** Whether the map has the member the access reaches, which is what a rule reading the deprecations asks to stay silent. */
	public function knows(Access $access, Types $types): bool
	{
		return array_any(
			$this->byName[strtolower($access->name)] ?? [],
			fn(array $entry) => $entry[0]->matches($access, $types),
		);
	}


	private function enterCall(MethodCallNode|StaticMethodCallNode|NewNode $node, RuleContext $context): void
	{
		$found = $this->findCallEntry($node, $context);
		if ($found === null) {
			return;
		}

		[$pattern, $template, $bindings, $access] = $found;
		$types = $context->getAnalysis(Types::class);
		$arguments = $node->arguments ?? ArgumentListNode::of();
		$rewrite = match (true) {
			$node instanceof MethodCallNode => $template->instantiate($bindings, $arguments, $node->object, nullsafe: $node->isNullsafe()),
			$node instanceof StaticMethodCallNode => $template->instantiate($bindings, $arguments, $node->class, static: true),
			default => $template->instantiate($bindings, $arguments, null),
		};
		if ($access->kind === MemberKind::Constructor) {
			$created = $node instanceof NewNode ? $types->findAccess($node)->classes ?? [] : [];
			$rewrite = self::keepConstructorCall($rewrite, $pattern, $node, ofChild: array_any($created, fn(string $class) => strcasecmp($class, $pattern->class) !== 0));
		}

		// parent::name() is written as a static call and says nothing of the method being one
		$kind = $access->kind === MemberKind::StaticMethod && $node instanceof StaticMethodCallNode && $node->class instanceof NameNode && $node->class->isSpecialClass()
			? MemberKind::Method
			: $access->kind;
		$message = $pattern->describe($kind) . " is replaced by $template->code";
		if (self::report($node instanceof NewNode ? $node->class : $node->name, $message, $rewrite, $context)) {
			$node->replaceWithExpression(self::write($rewrite, $node, $context));
		}
	}


	/** Whether a key has the call in the shape of its arguments, which is what replaced-members asks to leave it to this rule. */
	public function knowsCall(MethodCallNode|StaticMethodCallNode $node, RuleContext $context): bool
	{
		return $this->findCallEntry($node, $context) !== null;
	}


	/**
	 * The entry the call is of, the most specific one, with what it binds the arguments to and the access; null for none.
	 * @return ?array{MemberPattern, CallTemplate, ArgumentBindings, Access}
	 */
	private function findCallEntry(MethodCallNode|StaticMethodCallNode|NewNode $node, RuleContext $context): ?array
	{
		// the types are asked only about a name the map knows
		$name = match (true) {
			$node instanceof NewNode => '__construct',
			$node->name instanceof IdentifierNode => $node->name->text,
			default => null,
		};
		$entries = $name === null ? [] : $this->byName[strtolower($name)] ?? [];
		if ($entries === []) {
			return null;
		}

		$types = $context->getAnalysis(Types::class);
		$access = $types->findConstructorAccess($node) ?? $types->findAccess($node);
		if ($access === null) {
			return null;
		}

		$arguments = $node->arguments ?? ArgumentListNode::of();
		$parameters = $types->findParameters($access);
		foreach ($entries as [$pattern, $template]) {
			$bindings = $template instanceof CallTemplate && $pattern->matches($access, $types)
				? ($pattern->arguments ?? ArgumentPattern::parse('...'))->bind($arguments, $parameters)
				: null;
			if ($template instanceof CallTemplate && $bindings !== null) {
				return [$pattern, $template, $bindings, $access];
			}
		}

		return null;
	}


	/**
	 * The code of a constructor written as `new Class(...)` of the class of the key stands for the call as the code makes
	 * it: the instantiation keeps the class it creates, a child that declares no constructor among them, and
	 * `parent::__construct()` stays a call of it; any other code stands only for an instantiation of the class itself.
	 * @param  bool  $ofChild  the instantiation creates a child of the class
	 */
	private static function keepConstructorCall(
		Rewrite $rewrite,
		MemberPattern $pattern,
		MethodCallNode|StaticMethodCallNode|NewNode $node,
		bool $ofChild,
	): Rewrite
	{
		$new = $rewrite->expression;
		if (
			!$new instanceof NewNode
			|| !$new->class instanceof NameNode
			|| strcasecmp(ltrim($new->class->text, '\\'), $pattern->class) !== 0
		) {
			return match (true) {
				$new === null => $rewrite,
				$node instanceof StaticMethodCallNode => new Rewrite(null, ', but parent::__construct() is no instantiation the code could stand for'),
				$ofChild => new Rewrite(null, ', but it creates a child of the class, which the code does not'),
				default => $rewrite,
			};
		}

		$classes = array_values(array_filter($rewrite->classes, fn(NameNode $class) => $class !== $new->class));
		if ($node instanceof StaticMethodCallNode) {
			$arguments = $new->arguments ?? ArgumentListNode::of();
			$new->arguments = null;
			return new Rewrite(StaticMethodCallNode::of($node->class->withoutEdgeTrivia(), '__construct', $arguments), risk: $rewrite->risk, classes: $classes);
		}

		assert($node instanceof NewNode);
		$new->class = $node->class->withoutEdgeTrivia();
		return new Rewrite($new, risk: $rewrite->risk, classes: $classes);
	}


	/** A read of a property or of an offset; what writes it is rewritten where the writing stands, or reported here. */
	private function enterAccess(PropertyFetchNode|ArrayAccessNode $node, RuleContext $context): void
	{
		$use = self::findUse($node);
		$found = $use === null ? null : $this->findTemplate($node, 'get', [], $context);
		if ($found === null) {
			return;
		}

		[$message, $template, $bindings, $arguments] = $found;
		$rewrite = match (true) {
			$use === 'written' => new Rewrite(null, ', but it is written to here, which a call cannot be'),
			$template === null => new Rewrite(null, ', but nothing says what a read of it becomes'),
			default => $template->instantiate($bindings, $arguments, self::getReceiver($node), nullsafe: $node instanceof PropertyFetchNode && $node->isNullsafe()),
		};
		if ($use === 'guarded' && $rewrite->expression !== null) {
			$rewrite = new Rewrite($rewrite->expression, risk: $rewrite->risk ?? ', which no longer asks first whether it is set, as ?? does');
		}

		if (self::report($node instanceof PropertyFetchNode ? $node->name : $node->openBracket, $message, $rewrite, $context)) {
			$node->replaceWithExpression(self::write($rewrite, $node, $context));
		}
	}


	private function enterAssignment(AssignmentNode $node, RuleContext $context): void
	{
		$target = $node->target;
		$found = $target instanceof PropertyFetchNode || $target instanceof ArrayAccessNode
			? $this->findTemplate($target, 'set', [$node->expression], $context)
			: null;
		if ($found === null) {
			return;
		}

		[$message, $template, $bindings, $arguments] = $found;
		$rewrite = match (true) {
			$template === null => new Rewrite(null, ', but nothing says what an assignment to it becomes'),
			!$node->parent instanceof ExpressionStatementNode => new Rewrite(null, ', but the value of the assignment is used'),
			default => $template->instantiate($bindings, $arguments, self::getReceiver($target)),
		};
		if (self::report($target instanceof PropertyFetchNode ? $target->name : $target->openBracket, $message, $rewrite, $context)) {
			$node->replaceWithExpression(self::write($rewrite, $node, $context));
		}
	}


	private function enterIssetOrUnset(IssetNode|UnsetNode $node, RuleContext $context): void
	{
		foreach ($node->variables as $variable) {
			$found = $variable instanceof PropertyFetchNode || $variable instanceof ArrayAccessNode
				? $this->findTemplate($variable, $node instanceof IssetNode ? 'isset' : 'unset', [], $context)
				: null;
			if ($found === null) {
				continue;
			}

			[$message, $template, $bindings, $arguments] = $found;
			$rewrite = match (true) {
				$template === null => new Rewrite(null, ', but nothing says what ' . ($node instanceof IssetNode ? 'isset()' : 'unset()') . ' of it becomes'),
				count($node->variables) > 1 => new Rewrite(null, ', but it stands there along with others'),
				default => $template->instantiate($bindings, $arguments, self::getReceiver($variable)),
			};
			if (!self::report($variable instanceof PropertyFetchNode ? $variable->name : $variable->openBracket, $message, $rewrite, $context)) {
				continue;
			} elseif ($node instanceof IssetNode) {
				$node->replaceWithExpression(self::write($rewrite, $node, $context));
			} else {
				$statement = (new Parser)->parseStatement('0;');
				assert($statement instanceof ExpressionStatementNode);
				$statement->expression = self::write($rewrite, $node, $context);
				$node->replaceWith($statement);
			}
		}
	}


	private function enterDeclaration(MethodNode $node, RuleContext $context): void
	{
		$entries = $this->byName[strtolower($node->name->text)] ?? [];
		if ($entries === []) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$class = $types->findDeclaringClass($node);
		$entry = $class === null
			? null
			: array_find($entries, fn(array $entry) => $entry[1] instanceof CallTemplate
				&& $entry[0]->takesAnyArguments()
				&& $entry[0]->matchesDeclaration($class, $node->name->text, $types));
		if ($entry !== null) {
			$kind = $node->modifiers->isStatic() ? MemberKind::StaticMethod : MemberKind::Method;
			$context->report(
				$node->name,
				$entry[0]->describe($kind) . " is replaced by {$entry[1]->code}, but a declaration is nothing an expression could stand for",
				fixable: false,
			);
		}
	}


	/**
	 * What the map says of a property or an offset used the given way: the property of a key, else the magic method
	 * PHP calls for it, with the name or the key and the values as its arguments. The message, the template or null
	 * where the key has none for that use, and what the template is instantiated with; null where the map says nothing.
	 * @param  'get'|'set'|'isset'|'unset'  $use
	 * @param  list<ExpressionNode>  $values  what the magic method gets besides the name or the key
	 * @return ?array{string, ?CallTemplate, ArgumentBindings, ArgumentListNode}
	 */
	private function findTemplate(PropertyFetchNode|ArrayAccessNode $node, string $use, array $values, RuleContext $context): ?array
	{
		$name = $node instanceof PropertyFetchNode && $node->name instanceof IdentifierNode ? $node->name->text : null;
		$properties = $name === null ? [] : $this->byName[strtolower($name)] ?? [];
		$methods = $this->byName[strtolower(MagicCall::getMethod($node, $use))] ?? [];
		if (($properties === [] && $methods === []) || ($node instanceof PropertyFetchNode && $name === null)) {
			return null; // the types are asked only about a name the map knows
		}

		$types = $context->getAnalysis(Types::class);
		$values = array_map(fn(ExpressionNode $value) => $value->withoutEdgeTrivia(), $values);
		if ($node instanceof PropertyFetchNode) {
			$access = $types->findAccess($node);
			if ($access === null) {
				return null;
			}

			foreach ($properties as [$pattern, $templates]) {
				if ($templates instanceof PropertyTemplates && $pattern->matches($access, $types)) {
					$template = ['get' => $templates->get, 'set' => $templates->set][$use] ?? null;
					$arguments = ArgumentListNode::of(...$values);
					$bound = $arguments->findArgument(null, 0);
					return [
						$pattern->describe($access->kind) . ' is replaced by ' . ($template->code ?? ($templates->get ?? $templates->set)->code ?? 'nothing'),
						$template,
						new ArgumentBindings($bound === null ? [] : ['value' => $bound]),
						$arguments,
					];
				}
			}

		}

		$call = $methods === [] ? null : MagicCall::find($node, $use, $values, $types);
		if ($call === null) {
			return null;
		}

		foreach ($methods as [$pattern, $template]) {
			$bindings = $template instanceof CallTemplate ? $call->bind($pattern, $types) : null;
			if ($template instanceof CallTemplate && $bindings !== null) {
				return [$pattern->describe(MemberKind::Method) . " is replaced by $template->code", $template, $bindings, $call->arguments];
			}
		}

		return null;
	}


	/**
	 * How the property or the offset is used where it stands: read, read on the left of ??, which asks whether it is
	 * set before it reads, written in a way this rule rewrites where the writing stands (null), or written in a way
	 * no call can be.
	 * @return 'read'|'guarded'|'written'|null
	 */
	private static function findUse(PropertyFetchNode|ArrayAccessNode $node): ?string
	{
		$parent = $node->parent;
		if (
			($parent instanceof AssignmentNode && $parent->target === $node)
			|| ($parent instanceof SeparatedNodeList && ($parent->parent instanceof IssetNode || $parent->parent instanceof UnsetNode))
		) {
			return null;
		}

		// what is written to may be an element of it, or an item of a destructuring
		$written = $node;
		while (
			($parent instanceof ArrayAccessNode && $parent->expression === $written)
			|| $parent instanceof ListNode
			|| $parent instanceof ArrayNode
			|| $parent instanceof ArrayItemNode
			|| $parent instanceof SeparatedNodeList
		) {
			[$written, $parent] = [$parent, $parent->parent];
		}

		if ($parent instanceof BinaryOpNode && $parent->operator->text === '??' && $parent->left === $written) {
			return 'guarded';
		}

		return match (true) {
			$parent instanceof AssignmentNode,
			$parent instanceof CombinedAssignmentNode => $parent->target === $written,
			$parent instanceof AssignmentByReferenceNode,
			$parent instanceof PrefixOpNode,
			$parent instanceof PostfixOpNode,
			$parent instanceof IssetNode,
			$parent instanceof UnsetNode,
			$parent instanceof EmptyNode => true,
			$parent instanceof ForeachNode => $parent->key === $written || $parent->value === $written,
			$parent instanceof ArgumentNode => $parent->ampersand !== null,
			default => false,
		} ? 'written' : 'read';
	}


	private static function getReceiver(PropertyFetchNode|ArrayAccessNode $node): ExpressionNode
	{
		return $node instanceof PropertyFetchNode ? $node->object : $node->expression;
	}


	/**
	 * Reports the use with what the template made of it, and says whether it is to be rewritten.
	 * @phpstan-assert-if-true !null $rewrite->expression
	 */
	private static function report(Node|Token $at, string $message, Rewrite $rewrite, RuleContext $context): bool
	{
		return $context->report(
			$at,
			$message . ($rewrite->refusal ?? $rewrite->risk ?? ''),
			fixable: $rewrite->expression !== null,
			risky: $rewrite->risk !== null,
		) && $rewrite->expression !== null;
	}


	/** The expression written in place of the node, the classes of the template spelled the way the code there reaches them. */
	private static function write(Rewrite $rewrite, Node $node, RuleContext $context): ExpressionNode
	{
		assert($rewrite->expression !== null);
		foreach ($rewrite->classes as $class) {
			$class->text = NodeHelpers::spellClass(ltrim($class->text, '\\'), $node, $context);
		}

		// a call on what the replaced one was made on keeps a chain broken before its operator
		$expression = $rewrite->expression;
		if ($node instanceof MethodCallNode && $expression instanceof MethodCallNode && $expression->object->matches($node->object)) {
			$expression->object->getLastToken()?->setTrailingTrivia($node->object->getLastToken()->trailingTrivia ?? []);
			$expression->operator->setLeadingTrivia($node->operator->leadingTrivia);
		}

		return $expression;
	}


	/**
	 * @param  string|array{get: ?string, set: ?string}  $value
	 * @throws \InvalidArgumentException
	 */
	private static function createTemplate(string|array $value, MemberPattern $key): CallTemplate|PropertyTemplates
	{
		$member = "$key->class::" . ($key->kind === MemberKind::Property ? '$' : '') . $key->name;
		if ($key->kind === MemberKind::Property) {
			if (!is_array($value) || ($value['get'] ?? $value['set'] ?? null) === null) {
				throw new \InvalidArgumentException("The property $member is given {get: ..., set: ...}, what a read of it becomes and what an assignment to it does, at least one of them.");
			}

			return new PropertyTemplates(
				$value['get'] === null ? null : CallTemplate::fromCode($value['get'], new MemberPattern($key->class, MemberKind::Method, $key->name)),
				$value['set'] === null ? null : CallTemplate::fromCode($value['set'], new MemberPattern($key->class, MemberKind::Method, $key->name, ArgumentPattern::parse('$value'))),
			);
		}

		return is_array($value) || ($key->kind !== MemberKind::Method && $key->kind !== MemberKind::Constructor)
			? throw new \InvalidArgumentException("The member $member is given an expression instead, which takes a key that is a call, $member(...\$args) or one with the shape of its arguments, or a property.")
			: CallTemplate::fromCode($value, $key);
	}
}
