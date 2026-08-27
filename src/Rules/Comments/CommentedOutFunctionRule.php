<?php declare(strict_types=1);

namespace DressCode\Rules\Comments;

use DressCode\ConfigurableRule;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\CommentPolicy;
use PhpSyntax\Node;
use PhpSyntax\Nodes\ArgumentNode;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Scalar\BooleanNode;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function strlen;


/**
 * A statement calling a debugging function is commented out: `// var_dump($a);`. A statement sharing its
 * line with following code becomes a block comment instead. A call that may return its output instead of
 * printing it, `print_r($a, true)`, is left alone.
 */
#[RuleInfo(
	'dresscode/commented-out-function',
	Stage::Structure,
	description: 'Comments out statements calling the configured debugging functions',
	modifiesComments: true,
	risky: true,
)]
final class CommentedOutFunctionRule extends NodeRule implements ConfigurableRule
{
	/** function → the parameter switching it from printing to returning, by name and position */
	private const ReturnParameters = [
		'print_r' => ['return', 1],
		'var_export' => ['return', 1],
	];

	/** @var list<string> */
	private array $functions = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'functions' => Expect::listOf('string')->default(['print_r', 'var_dump', 'var_export']),
		]);
	}


	public function configure(array $options): void
	{
		$this->functions = $options['functions'];
	}


	public function getVisitedTypes(): array
	{
		return [ExpressionStatementNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof ExpressionStatementNode
			|| !$node->parent instanceof NodeList
			|| !$node->semicolon->is(';')
			|| !($call = $node->expression) instanceof FunctionCallNode
			|| !($first = $node->getFirstToken())
			|| !($next = $node->semicolon->getNext())
		) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$function = array_find($this->functions, fn(string $candidate) => $resolver->isGlobalFunctionCall($call, $candidate));

		if ($function === null || self::canReturn($call, $function)) {
			return;
		}

		$code = (string) $node;
		$leading = implode('', array_map(fn(Trivia $trivia) => $trivia->text, $first->leadingTrivia));
		$trailing = implode('', array_map(fn(Trivia $trivia) => $trivia->text, $node->semicolon->trailingTrivia));
		$code = substr($code, strlen($leading), strlen($code) - strlen($leading) - strlen($trailing));

		$endsLine = $next->kind === TokenKind::EndOfFile;
		foreach ($node->semicolon->trailingTrivia as $trivia) {
			$endsLine = $endsLine || $trivia->isEndOfLine();
		}

		if (
			(!$endsLine && str_contains($code, '*/'))
			|| !$context->report($node, "The call of $function() must be commented out")
		) {
			return;
		}

		$comments = $endsLine
			? self::lineComments($code, $first->getLineIndentation(), $context->getStyle()->eol)
			: [new Trivia(TriviaKind::Comment, "/* $code */")];
		$trivia = [...$first->leadingTrivia, ...$comments, ...$node->semicolon->trailingTrivia];
		$first->setLeadingTrivia([]);
		$node->semicolon->setTrailingTrivia([]);
		$node->remove(CommentPolicy::Drop);
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
		return $value !== null && !($value instanceof BooleanNode && !$value->value);
	}


	/**
	 * Every line of the code as a `//` comment on its own line, indented like the statement.
	 * @return list<Trivia>
	 */
	private static function lineComments(string $code, string $indentation, string $eol): array
	{
		$trivia = [];
		foreach (preg_split('~\r\n|\n|\r~', $code) ?: [] as $i => $line) {
			if ($i > 0) {
				$trivia[] = new Trivia(TriviaKind::EndOfLine, $eol);
				if ($indentation !== '') {
					$trivia[] = new Trivia(TriviaKind::Whitespace, $indentation);
				}

				if (str_starts_with($line, $indentation)) {
					$line = substr($line, strlen($indentation));
				}
			}

			$trivia[] = new Trivia(TriviaKind::Comment, rtrim("// $line"));
		}

		return $trivia;
	}
}
