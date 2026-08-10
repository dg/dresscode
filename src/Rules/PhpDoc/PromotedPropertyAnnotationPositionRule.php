<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Style};
use DressCode\Domains\Shapes;
use DressCode\Rules\NativeType;
use PHPStan\PhpDocParser\Ast\PhpDoc\{ParamTagValueNode, PhpDocTagNode};
use PhpSyntax\{Indentation, Node, Token, Trivia};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\ParameterNode;
use function count, strlen;


/**
 * A promoted property is documented where it is declared: a `@param` of the constructor for a promoted parameter
 * becomes a doc comment of the parameter, `@var` with the type when it says more than the native one,
 * the description alone when it does not, nothing when there is neither; a constructor doc comment left with
 * nothing is removed. The description keeps the lines it was written on, so a comment of several lines stays
 * several lines. A parameter that already has a doc comment is left alone.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [PhpDoc::class])]
final class PromotedPropertyAnnotationPositionRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision('phpdoc.promotedPropertyAnnotation', new Shapes(['atProperty' => ['@var', 'a `@var` at the property, not a `@param` of the constructor']]), 'The annotation of a promoted property')];
	}


	public function getVisitedNodes(): array
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
			if ($param->promoted && $param->variable->name instanceof Token && $param->getDocComment() === null) {
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
				|| !$context->report($param, "The promoted property `$value->parameterName` must be documented at its declaration, not by a `@param`.", trivia: $docComment)
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
			if ($param->type === null || !NativeType::matches($value->type, $param->type->text)) {
				$lines = [trim('@var ' . $value->type . ' ' . ($lines[0] ?? '')), ...array_slice($lines, 1)];
			}

			if ($lines !== []) {
				self::annotate($param, $lines, $context->style);
			}
		}

		$tree->children = $kept;
		$phpDoc->writeBack($tree, $docComment, $node);
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
	 * that does not start a line, as trailing trivia of the token before; for several lines the parameter first moves
	 * to a line of its own.
	 * @param list<string> $lines
	 */
	private static function annotate(ParameterNode $param, array $lines, Style $style): void
	{
		$first = $param->getFirstToken();
		$eol = $style->lineEnding;
		if (!$first->startsLine()) {
			if (count($lines) > 1) {
				$first->ensureStartsLine($eol);
				$first->setIndentation(Indentation::infer($first, $style->toPhpSyntax()));
			} elseif ($previous = $first->getPrevious()) {
				$docComment = new Trivia(Trivia::DocComment, "/** $lines[0] */");
				$previous->setTrailingTrivia([...$previous->trailingTrivia, $docComment, Trivia::fromText(' ')]);
				return;
			}
		}

		$indentation = $first->getLineIndentation();
		$text = count($lines) === 1
			? "/** $lines[0] */"
			: '/**' . implode('', array_map(fn(string $line) => rtrim("$eol$indentation * $line"), $lines)) . "$eol$indentation */";
		$trivia = [...$first->leadingTrivia, new Trivia(Trivia::DocComment, $text), Trivia::fromText($eol)];
		if ($indentation !== '') {
			$trivia[] = new Trivia(Trivia::Whitespace, $indentation);
		}

		$first->setLeadingTrivia($trivia);
	}
}
