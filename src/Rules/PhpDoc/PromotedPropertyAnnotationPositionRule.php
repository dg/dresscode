<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\NativeType;
use DressCode\Analyses\PhpDoc;
use DressCode\NodeRule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PhpSyntax\Indentation;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\ParameterNode;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function array_slice, count, strlen;


/**
 * A promoted property is documented where it is declared: a `@param` of the constructor for a promoted parameter
 * becomes a doc comment of the parameter, `@var` with the type when it says more than the native one,
 * the description alone when it does not, nothing when there is neither; a constructor doc comment left with
 * nothing is removed. The description keeps the lines it was written on, so a comment of several lines stays
 * several lines. A parameter that already has a doc comment is left alone.
 */
#[RuleInfo(
	'dresscode/promoted-property-annotation-position',
	Stage::Structure,
	description: 'Moves the @param annotation of a promoted property to a doc comment at the property',
	modifiesComments: true,
)]
final class PromotedPropertyAnnotationPositionRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [MethodNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof MethodNode
			|| !$node->isConstructor()
			|| ($docComment = $node->getDocComment()) === null
			|| $docComment->inInterpolation
		) {
			return;
		}

		$promoted = [];
		foreach ($node->parameters->getItems() as $param) {
			if ($param->isPromoted() && $param->variable->name instanceof Token && $param->getDocComment() === null) {
				$promoted[$param->variable->name->text] = $param;
			}
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($docComment);
		$kept = [];
		$moved = [];
		foreach ($tree->children as $child) {
			$value = $child instanceof PhpDocTagNode && $child->value instanceof ParamTagValueNode ? $child->value : null;
			$param = $value === null ? null : $promoted[$value->parameterName] ?? null;
			if (
				$value === null
				|| $param === null
				|| !$context->report($param, "The promoted property $value->parameterName must be documented at its declaration, not by a @param", trivia: $docComment)
			) {
				$kept[] = $child;
				continue;
			}

			unset($promoted[$value->parameterName]); // a second @param for the same property stays where it is
			$moved[] = [$param, $value];
		}

		if ($moved === []) {
			return;
		}

		foreach ($moved as [$param, $value]) {
			$lines = self::splitDescription($value->description);
			if ($param->type === null || !NativeType::matches($value->type, trim((string) $param->type))) {
				$lines = [trim('@var ' . $value->type . ' ' . ($lines[0] ?? '')), ...array_slice($lines, 1)];
			}

			if ($lines !== []) {
				self::annotate($param, $lines, $context->getStyle());
			}
		}

		$tree->children = $kept;
		if (PhpDoc::isEmpty($tree)) {
			$node->removeDocComment();
		} else {
			$node->replaceDocComment($phpDoc->print($tree, $docComment));
		}
	}


	/**
	 * The lines of the description as they were written, blank ones at the edges left out and the indentation
	 * the continuation lines share taken off; the parser gives the first line without any.
	 * @return list<string>
	 */
	private static function splitDescription(string $description): array
	{
		$description = trim($description);
		if ($description === '') {
			return [];
		}

		$lines = array_map(rtrim(...), preg_split('~\R~', $description));
		$shared = null;
		foreach (array_slice($lines, 1) as $line) {
			if ($line === '') {
				continue;
			}

			$indentation = substr($line, 0, strspn($line, " \t"));
			$shared ??= $indentation;
			while (!str_starts_with($indentation, $shared)) {
				$shared = substr($shared, 0, -1);
			}
		}

		$rest = array_map(fn(string $line) => substr($line, strlen($shared ?? '')), array_slice($lines, 1));
		return [$lines[0], ...$rest];
	}


	/**
	 * Puts the doc comment above the parameter on lines of its own; a single line goes in front of a parameter
	 * that does not start a line, as trailing trivia of the token before, several lines put it on one first.
	 * @param list<string> $lines
	 */
	private static function annotate(ParameterNode $param, array $lines, Style $style): void
	{
		$first = $param->getFirstToken();
		if ($first === null) {
			return;
		}

		$eol = $style->eol;
		if (!$first->startsLine()) {
			if (count($lines) > 1) {
				$first->ensureLeadingNewline($eol);
				$first->setIndentation(Indentation::infer($first, $style));
			} elseif ($previous = $first->getPrevious()) {
				$docComment = new Trivia(TriviaKind::DocComment, "/** $lines[0] */");
				$previous->setTrailingTrivia([...$previous->trailingTrivia, $docComment, new Trivia(TriviaKind::Whitespace, ' ')]);
				return;
			}
		}

		$indentation = $first->getLineIndentation();
		$text = count($lines) === 1
			? "/** $lines[0] */"
			: '/**' . implode('', array_map(fn(string $line) => rtrim("$eol$indentation * $line"), $lines)) . "$eol$indentation */";
		$trivia = [...$first->leadingTrivia, new Trivia(TriviaKind::DocComment, $text), new Trivia(TriviaKind::EndOfLine, $eol)];
		if ($indentation !== '') {
			$trivia[] = new Trivia(TriviaKind::Whitespace, $indentation);
		}

		$first->setLeadingTrivia($trivia);
	}
}
