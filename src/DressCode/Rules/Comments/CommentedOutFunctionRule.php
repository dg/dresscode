<?php declare(strict_types=1);

namespace DressCode\Rules\Comments;

use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\CommentPolicy;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Expression\FunctionCallNode;
use PhpSyntax\Nodes\NodeList;
use PhpSyntax\Nodes\Statement\ExpressionStatementNode;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function strlen;


/**
 * A statement calling a debugging function is commented out: `// var_dump($a);`. A statement sharing its
 * line with following code becomes a block comment instead.
 */
#[RuleInfo(
	'dresscode/commented-out-function',
	Stage::Structure,
	description: 'Comments out statements calling the configured debugging functions',
	modifiesComments: true,
)]
final class CommentedOutFunctionRule extends Rule implements ConfigurableRule
{
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
			|| !($call = $node->expr) instanceof FunctionCallNode
			|| !($first = $node->getFirstToken())
			|| !($next = $node->semicolon->getNext())
		) {
			return;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$function = null;
		foreach ($this->functions as $candidate) {
			if ($resolver->isGlobalFunctionCall($call, $candidate)) {
				$function = $candidate;
				break;
			}
		}

		if ($function === null) {
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
