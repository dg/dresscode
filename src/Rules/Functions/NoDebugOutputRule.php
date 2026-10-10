<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\{Decision, DecisionKind, NodeRule, Risk, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\{Names, Words};
use DressCode\Rules\GlobalCalls;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{CommentPolicy, Node, Token, Trivia};
use PhpSyntax\Nodes\{ArgumentNode, PlainNodeList};
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use function strlen;


/**
 * A statement calling a debugging function is commented out: `// var_dump($a);`. A statement sharing its
 * line with following code becomes a block comment instead. A call that may return its output instead of
 * printing it, `print_r($a, true)`, is left alone.
 *
 * The fix is risky: whatever the call printed is not printed any more.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [NameResolver::class])]
final class NoDebugOutputRule extends NodeRule
{
	/** function => the parameter switching it from printing to returning, by name and position */
	private const ReturnParameters = [
		'print_r' => ['return', 1],
		'var_export' => ['return', 1],
	];

	/** @var array<lowercase-string, string>  lowercased name => the name as configured, the first of those spelled alike */
	private array $functions = [];


	public static function getDecisions(): array
	{
		return [
			new Decision('correctness.debugOutput.statement', new Words(['commentedOut' => 'the statement changed into a comment, `// var_dump($a);`']), 'A statement calling a debugging function to print, a call that may return its output instead, `print_r($a, true)`, staying'),
			new Decision('correctness.debugOutput.functions', new Names, 'The debugging functions whose calls print', kind: DecisionKind::Parameter, default: ['print_r', 'var_dump', 'var_export']),
		];
	}


	public function configure(Values $values): void
	{
		$this->functions = [];
		foreach ($values->get('correctness.debugOutput.functions')->getNames() as $function) {
			$this->functions[strtolower($function)] ??= $function;
		}
	}


	public function getVisitedNodes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ExpressionStatementNode
			|| !$node->parent instanceof PlainNodeList
			|| !$node->semicolon->is(';')
			|| !($call = $node->expression) instanceof FunctionCallNode
			|| ($found = GlobalCalls::findFunction($call, $this->functions, $context)) === null
			|| self::canReturn($call, $function = $this->functions[$found])
			|| !($next = $node->semicolon->getNext())
		) {
			return;
		}

		$first = $node->getFirstToken();
		$code = $node->text;
		$endsLine = $next->is(Token::EndOfFile);
		foreach ($node->semicolon->trailingTrivia as $trivia) {
			$endsLine = $endsLine || $trivia->isLineEnding();
		}

		if (
			// a block comment ends at its closing marker, a line comment at a close tag
			($endsLine ? str_contains($code, '?>') : str_contains($code, '*/'))
			|| !$context->report($node, "The call of `$function()` must be commented out.", risk: Risk::BehaviorChanges, because: 'what the call printed disappears from the output')
		) {
			return;
		}

		$comments = $endsLine
			? self::createLineComments($code, $first->getLineIndentation(), $context->style->lineEnding)
			: [Trivia::fromText("/* $code */")];
		$trivia = [...$first->leadingTrivia, ...$comments, ...$node->semicolon->trailingTrivia];
		$first->setLeadingTrivia([]);
		$node->semicolon->setTrailingTrivia([]);
		$node->remove(CommentPolicy::StayWithNode);
		$next->setLeadingTrivia([...$trivia, ...$next->leadingTrivia]);
	}


	/**
	 * Whether the call may return its output: the parameter switching the function to it is given as anything
	 * but false, or an unpacked argument may give it.
	 */
	private static function canReturn(FunctionCallNode $call, string $function): bool
	{
		[$parameter, $position] = self::ReturnParameters[strtolower($function)] ?? [null, 0];
		if ($parameter === null) {
			return false;
		}

		foreach ($call->arguments->items as $argument) {
			if ($argument instanceof ArgumentNode && $argument->ellipsis !== null) {
				return true;
			}
		}

		$value = $call->arguments->findArgument($parameter, $position)?->value;
		return $value !== null && !($value instanceof BooleanNode && !$value->toValue());
	}


	/**
	 * Every line of the code as a `//` comment on its own line, indented like the statement.
	 * @return list<Trivia>
	 */
	private static function createLineComments(string $code, string $indentation, string $eol): array
	{
		$trivia = [];
		foreach (preg_split('~\r\n|\n|\r~', $code) ?: [] as $i => $line) {
			if ($i > 0) {
				$trivia[] = Trivia::fromText($eol);
				if ($indentation !== '') {
					$trivia[] = Trivia::fromText($indentation);
				}

				if (str_starts_with($line, $indentation)) {
					$line = substr($line, strlen($indentation));
				}
			}

			$trivia[] = Trivia::fromText(rtrim("// $line"));
		}

		return $trivia;
	}
}
