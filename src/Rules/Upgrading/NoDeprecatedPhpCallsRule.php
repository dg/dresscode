<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Upgrading;

use DressCode\{Config, Decision, Domain, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Map;
use DressCode\Rules\{CodeWriter, GlobalCalls, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentNode, ClosureUseNode, Expression, FunctionLikeNode, IdentifierNode, NameNode, PlainNodeList};
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use function count, strlen;


/**
 * What the upgrading data of PHP say of a call PHP retired (`PhpUpgradingData`): a deprecated function is reported whatever the
 * target, since the code may run on the version that deprecated it; a call the data write otherwise is written so
 * where the target has what replaces it; and a call doing nothing on the target goes, with the statement it makes.
 * An argument whose value PHP deprecated is reported too (`DeprecatedArguments`).
 *
 * Only a call standing as a statement is removed, and only one whose arguments would do nothing when they ran: a
 * call whose value something takes is a question about the code rather than a freeing. A call that is the body of
 * a construct without braces, `if ($h) curl_close($h);`, is only reported, having no list to leave. A comment inside
 * a call leaves it alone.
 *
 * An entry of a method, `ReflectionProperty::setAccessible()`, stands for a call on a variable that the function it
 * stands in assigns once and only once, from `new` of that class; a receiver of any other shape stays, because the
 * method of another class is another method, and a function reaching a variable by a name it does not spell out
 * keeps all its calls. The types are not asked.
 */
#[RuleInfo(Stage::Structure, analyses: [NameResolver::class])]
final class NoDeprecatedPhpCallsRule extends NodeRule
{
	/** @var array<string, true>  the names of the entries withdrawn, in lower case */
	private array $except = [];

	private readonly PhpUpgradingData $data;


	/** The upgrading data of PHP DressCode ships, or others a test gives. */
	public function __construct(?PhpUpgradingData $data = null)
	{
		$this->data = $data ?? PhpUpgradingData::fromFile();
	}


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'upgrading.php.deprecatedCall',
				Domain::state(),
				'A call PHP retired is written as the upgrading data of PHP say: a deprecated function reported, `utf8_encode()` written with `mb_convert_encoding()`, `curl_close()` and the other calls doing nothing on the target removed',
			),
			new Decision(
				'upgrading.php.deprecatedCallExcept',
				new Map(Domain::state(), caseInsensitive: true),
				'The entries of the upgrading data of PHP withdrawn, by the name of the function or `Class::method`, each written `name: keep`',
				parameter: true,
				default: [],
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->except = [];
		foreach ($values->get('upgrading.php.deprecatedCallExcept')->getEntries() as $name => $entry) {
			if ($entry->isKept()) {
				$this->except[strtolower((string) $name)] = true;
			}
		}
	}


	public function getVisitedNodes(): array
	{
		return [Expression\FunctionCallNode::class, Expression\MethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if ($node instanceof Expression\FunctionCallNode) {
			$this->enterFunctionCall($node, $context);
		} elseif ($node instanceof Expression\MethodCallNode) {
			$this->enterMethodCall($node, $context);
		}
	}


	private function enterFunctionCall(Expression\FunctionCallNode $call, RuleContext $context): void
	{
		DeprecatedArguments::check($call, $context, $this->except);
		$entries = $this->data->getEntries();
		$function = GlobalCalls::findFunction($call, $entries, $context);
		if ($function === null || isset($this->except[$function])) {
			return;
		}

		foreach ($entries[$function] as $entry) {
			if ($entry->operation !== UpgradingOperation::Report && version_compare($context->phpVersion, $entry->appliesFrom, '>=')) {
				$bindings = $entry->pattern instanceof FunctionPattern ? $entry->pattern->bind($call->arguments) : null;
				if ($bindings !== null && self::rewriteCall($entry, $call, "$function()", $bindings, $context)) {
					return;
				}
			}
		}

		$deprecation = array_find($entries[$function], fn(UpgradingEntry $entry) => $entry->operation === UpgradingOperation::Report);
		if ($deprecation !== null && $call->name instanceof NameNode) {
			// the oldest version DressCode targets stands for every version before it, so it names no version
			$context->report(
				$call->name,
				"Function `$function()` is deprecated" . ($deprecation->retiredIn === Config::MinPhpVersion ? '' : " since PHP $deprecation->retiredIn") . '.',
				fixable: false,
			);
		}
	}


	private function enterMethodCall(Expression\MethodCallNode $call, RuleContext $context): void
	{
		if ($call->nullsafe || !$call->name instanceof IdentifierNode || !$call->object instanceof Expression\VariableNode) {
			return;
		}

		foreach ($this->data->getMethodEntries($call->name->text) as [$name, $entry]) {
			if (
				$entry->pattern instanceof MemberPattern
				&& !isset($this->except[$name])
				&& version_compare($context->phpVersion, $entry->appliesFrom, '>=')
				&& self::isVariableOf($call->object, $entry->pattern->class, $context)
				&& ($bindings = ($entry->pattern->arguments ?? ArgumentPattern::any())->bind($call->arguments)) !== null
				&& self::rewriteCall($entry, $call, $call->name->text . '()', $bindings, $context)
			) {
				return;
			}
		}
	}


	/** Writes what the entry says of the call; false where it says nothing of this one. */
	private static function rewriteCall(
		UpgradingEntry $entry,
		Expression\FunctionCallNode|Expression\MethodCallNode $call,
		string $subject,
		?ArgumentBindings $bindings,
		RuleContext $context,
	): bool
	{
		$uncertainty = $call instanceof Expression\FunctionCallNode ? GlobalCalls::findUncertainty($call, $context) : null;
		if ($entry->operation === UpgradingOperation::Remove) {
			$statement = $call->parent;
			if (!$statement instanceof ExpressionStatementNode || !self::hasPlainArguments($call)) {
				return false;
			} elseif (
				!$statement->hasInnerComment()
				&& $context->report(
					$call,
					"Useless `$subject` call, because it does nothing since PHP $entry->appliesFrom.",
					fixable: $statement->parent instanceof PlainNodeList,
					risk: $uncertainty === null ? null : Risk::NameUncertain,
					because: $uncertainty,
				)
			) {
				$statement->remove();
			}

			return true;
		}

		$write = (string) $entry->write;
		$name = $call->name;
		if (
			!$call instanceof Expression\FunctionCallNode
			|| !$name instanceof NameNode
			|| $bindings === null
			|| !preg_match('~^(\w+)\(~', $write, $m)
			|| $call->hasInnerComment()
		) {
			return false;
		} elseif ($context->report(
			$call,
			"The deprecated `$subject` call must be written with `$m[1]()`.",
			risk: $entry->risk ?? ($uncertainty === null ? null : Risk::NameUncertain),
			because: $entry->risk === null ? $uncertainty : $entry->because,
		)) {
			$values = array_map(fn(ArgumentNode|array $argument) => $argument instanceof ArgumentNode ? $argument->value : null, $bindings->arguments);
			$code = CodeWriter::spellFunction($m[1], $name, $context) . substr($write, strlen($m[1]));
			$call->replaceWith((new Builder)->expression($code, ...array_filter($values)));
		}

		return true;
	}


	/** Whether every argument is written out and reading it again would do nothing, so that dropping it is free. */
	private static function hasPlainArguments(Expression\FunctionCallNode|Expression\MethodCallNode $call): bool
	{
		foreach ($call->arguments->items as $argument) {
			if (
				!$argument instanceof ArgumentNode
				|| $argument->ampersand !== null
				|| $argument->ellipsis !== null
				|| !$argument->value->isRepeatableRead()
			) {
				return false;
			}
		}

		return true;
	}


	/** Whether the variable holds an object of the class and nothing else, by the one assignment of `new` it has. */
	private static function isVariableOf(Expression\VariableNode $variable, string $class, RuleContext $context): bool
	{
		$name = $variable->plainName;
		$owner = $variable->findAncestor(FunctionLikeNode::class);
		$scope = $owner ?? $variable->getFile();
		if ($name === null || $scope === null || NodeHelpers::findDynamicVariableAccesses($scope, $context) !== []) {
			return false;
		}

		$assignments = [];
		foreach ($scope->find(Expression\VariableNode::class, fn(Expression\VariableNode $v) => $v->plainName === $name) as $occurrence) {
			$parent = $occurrence->parent;
			if ($parent instanceof ClosureUseNode) {
				// a closure holding the variable by reference writes it from wherever it is called
				if ($parent->ampersand !== null) {
					return false;
				}
			} elseif ($occurrence->findAncestor(FunctionLikeNode::class) !== $owner) {
				continue; // a variable of a nested function is another variable of the same name
			} elseif ($parent instanceof Expression\AssignmentNode && $parent->target === $occurrence) {
				$assignments[] = $parent->expression;
			} elseif (self::isWritten($occurrence)) {
				return false;
			}
		}

		$new = count($assignments) === 1 ? $assignments[0] : null;
		return $new instanceof Expression\NewNode
			&& $new->class instanceof NameNode
			&& strcasecmp($context->getAnalysis(NameResolver::class)->resolveClass($new->class), $class) === 0;
	}


	/** Whether something other than a plain assignment writes the variable, which makes its value unknown. */
	private static function isWritten(Expression\VariableNode $variable): bool
	{
		$parent = $variable->parent;
		return $parent instanceof Expression\AssignmentByReferenceNode
			|| $parent instanceof Expression\CombinedAssignmentNode
			|| $parent instanceof Expression\PrefixOpNode
			|| $parent instanceof Expression\PostfixOpNode
			|| ($parent instanceof ArgumentNode && $parent->ampersand !== null);
	}
}
