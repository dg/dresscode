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
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\PhpDoc\{ParamTagValueNode, PhpDocTagNode};
use PHPStan\PhpDocParser\Lexer\Lexer;
use PhpSyntax\{Indentation, Node, Token, Trivia};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\ParameterNode;
use function count, strlen;


/**
 * A promoted property is documented where it is declared: a `@param` of the constructor for a promoted parameter
 * becomes a doc comment of the parameter, `@var` with the type when it says more than the native one,
 * the description alone when it does not, nothing when there is neither; a constructor doc comment left with
 * nothing is removed. The description keeps the lines it was written on, so a comment of several lines stays
 * several lines. A parameter that already has a doc comment keeps it, and a `@param` beside it goes where it has
 * no description and the type of the `@var`, or of the declaration when there is no `@var`; any other is reported
 * for the author to settle.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [PhpDoc::class])]
final class PromotedPropertyAnnotationPositionRule extends NodeRule
{
	public static function getDecisions(): array
	{
		return [new Decision(
			'phpdoc.property.promoted',
			new Shapes(['atProperty' => ['@var', 'a `@var` at the property, not a `@param` of the constructor']]),
			'The annotation of a promoted property',
		)];
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
		) {
			return;
		}

		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$promoted = $documented = [];
		foreach ($node->parameters->getItems() as $param) {
			if (!$param->promoted || !$param->variable->name instanceof Token) {
				continue;
			} elseif (($own = $param->getDocComment()) === null) {
				$promoted[$param->variable->name->text] = $param;
			} else {
				$var = $phpDoc->parse($own)->getVarTagValues()[0] ?? null;
				$documented[$param->variable->name->text] = [$param, $var === null ? null : (string) $var->type];
			}
		}

		$tree = $phpDoc->parse($docComment);
		$tokens = $phpDoc->getTokens($docComment);
		$kept = [];
		$moved = [];
		foreach ($tree->children as $child) {
			$value = $child instanceof PhpDocTagNode && $child->value instanceof ParamTagValueNode ? $child->value : null;
			if ($value !== null && isset($documented[$value->parameterName])) {
				// a `@param` only repeating the declaration goes, one saying more or something else is left to the author
				[$own, $varType] = $documented[$value->parameterName];
				$same = trim($value->description) === '' && ($varType === null
					? $own->type !== null && NativeType::matches($value->type, $own->type->text)
					: $varType === (string) $value->type);
				if (
					!$context->report(
						$own,
						"The promoted property `$value->parameterName` is documented at its declaration and by a `@param` as well.",
						fixable: $same,
					)
					|| !$same
				) {
					$kept[] = $child;
				}

				continue;
			}

			$param = $value === null ? null : $promoted[$value->parameterName] ?? null;
			if (
				$value === null
				|| $param === null
				|| !$context->report(
					$param,
					"The promoted property `$value->parameterName` must be documented at its declaration, not by a `@param`.",
					trivia: $docComment,
				)
			) {
				$kept[] = $child;
				continue;
			}

			unset($promoted[$value->parameterName]); // a second @param for the same property stays where it is
			$moved[] = [$param, $value, self::extractType($value, $tokens)];
		}

		if (count($kept) === count($tree->children)) {
			return;
		}

		foreach ($moved as [$param, $value, $type]) {
			$lines = self::splitDescription($value->description);
			if ($param->type === null || !NativeType::matches($value->type, $param->type->text)) {
				$lines = [trim("@var $type " . ($lines[0] ?? '')), ...array_slice($lines, 1)];
			}

			if ($lines !== []) {
				self::annotate($param, $lines, $context->style);
			}
		}

		PhpDoc::keepChildren($tree, $kept);
		$phpDoc->writeBack($tree, $docComment, $node);
	}


	/**
	 * The type of the tag as it was written, from its first token to the name of the parameter; printing the node
	 * would wrap a union in parentheses with spaces and drop the parentheses that make a callable nullable.
	 * @param  list<array{string, int, int}>  $tokens
	 */
	private static function extractType(ParamTagValueNode $value, array $tokens): string
	{
		$text = '';
		$end = [Lexer::TOKEN_VARIABLE, Lexer::TOKEN_REFERENCE, Lexer::TOKEN_VARIADIC];
		for (
			$i = $value->type->getAttribute(Attribute::START_INDEX);
			$i < count($tokens) && !in_array($tokens[$i][Lexer::TYPE_OFFSET], $end, true);
			$i++
		) {
			$text .= $tokens[$i][Lexer::VALUE_OFFSET];
		}

		return rtrim($text);
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
	 * Puts the doc comment above the parameter on lines of its own, the parameter first moving to a line of its own,
	 * as a property declared in the class has it.
	 * @param list<string> $lines
	 */
	private static function annotate(ParameterNode $param, array $lines, Style $style): void
	{
		$first = $param->getFirstToken();
		$eol = $style->lineEnding;
		if (!$first->startsLine()) {
			$first->ensureStartsLine($eol);
			$first->setIndentation(Indentation::infer($first, $style->toPhpSyntax()));
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
