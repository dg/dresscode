<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Domains\Words;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\{PhpDocTagNode, PhpDocTextNode};
use PHPStan\PhpDocParser\Lexer\Lexer;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Node, SymbolKind, Token, Trivia};


/**
 * Known annotations are written in their canonical case: `@inheritDoc`, `@dataProvider`, `@phpstan-return`.
 * An annotation whose name is an imported class (a Doctrine annotation, for instance) is left alone.
 */
#[RuleInfo(Stage::Structure, modifiesComments: true, analyses: [PhpDoc::class, NameResolver::class])]
final class AnnotationCasingRule extends NodeRule
{
	private const Standard = [
		'api', 'author', 'category', 'copyright', 'deprecated', 'example', 'filesource', 'global', 'ignore',
		'inheritDoc', 'internal', 'license', 'link', 'method', 'package', 'param', 'property', 'property-read',
		'property-write', 'return', 'see', 'since', 'source', 'subpackage', 'throws', 'todo', 'uses', 'used-by',
		'var', 'version',
	];

	private const StaticAnalysis = [
		'allow-private-mutation', 'assert', 'assert-if-true', 'assert-if-false', 'consistent-constructor',
		'consistent-templates', 'extends', 'external-mutation-free', 'implements', 'mixin', 'ignore-falsable-return',
		'ignore-nullable-return', 'ignore-var', 'ignore-variable-method', 'ignore-variable-property', 'immutable',
		'import-type', 'method', 'mutation-free', 'no-named-arguments', 'param', 'param-out', 'property',
		'property-read', 'property-write', 'pure', 'readonly', 'readonly-allow-private-mutation', 'require-extends',
		'require-implements', 'return', 'seal-properties', 'self-out', 'template', 'template-covariant',
		'template-extends', 'template-implements', 'template-use', 'this-out', 'type', 'var', 'yield',
	];

	private const StaticAnalysisPrefixes = ['phpstan', 'psalm', 'phan'];

	private const PhpUnit = [
		'after', 'afterClass', 'backupGlobals', 'backupStaticAttributes', 'before', 'beforeClass',
		'codeCoverageIgnore', 'codeCoverageIgnoreStart', 'codeCoverageIgnoreEnd', 'covers', 'coversDefaultClass',
		'coversNothing', 'dataProvider', 'depends', 'doesNotPerformAssertions', 'group', 'large', 'medium',
		'preserveGlobalState', 'requires', 'runTestsInSeparateProcesses', 'runInSeparateProcess', 'small', 'test',
		'testdox', 'testWith', 'ticket', 'uses',
	];


	public static function getDecisions(): array
	{
		return [new Decision('phpdoc.annotations', new Words(['canonicalCase' => 'as it is known, `@inheritDoc`, `@phpstan-var`']), 'The letter case of a known annotation')];
	}


	public function getVisitedNodes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || !$node->hasComment()) {
			return;
		}

		foreach ($node->getDocComments() as $trivia) {
			$this->processDocComment($node, $trivia, $context);
		}
	}


	private function processDocComment(Token $token, Trivia $trivia, RuleContext $context): void
	{
		$names = self::getCanonicalNames();
		$imports = $context->getAnalysis(NameResolver::class)->getImports(SymbolKind::ClassLike, $token);
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tokens = $phpDoc->getTokens($trivia);
		$renamed = []; // index of the token of the name => canonical name
		foreach ($phpDoc->parse($trivia)->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				$canonical = $names[strtolower($child->name)] ?? null;
				if (
					$canonical !== null
					&& $canonical !== $child->name
					&& !isset($imports[strtolower(substr($child->name, 1))])
					// a Doctrine annotation is a class name and its value repeats it, so renaming would not show
					&& !$child->value instanceof DoctrineTagValueNode
				) {
					$renamed[$child->getAttribute(Attribute::START_INDEX)] = $canonical;
				}

			} elseif ($child instanceof PhpDocTextNode) {
				// an inline tag such as `{@inheritDoc}`
				for ($i = $child->getAttribute(Attribute::START_INDEX); $i <= $child->getAttribute(Attribute::END_INDEX); $i++) {
					$canonical = $names[strtolower($tokens[$i][Lexer::VALUE_OFFSET])] ?? null;
					if (
						$canonical !== null
						&& $canonical !== $tokens[$i][Lexer::VALUE_OFFSET]
						&& $tokens[$i][Lexer::TYPE_OFFSET] === Lexer::TOKEN_PHPDOC_TAG
						&& ($tokens[$i - 1][Lexer::TYPE_OFFSET] ?? null) === Lexer::TOKEN_OPEN_CURLY_BRACKET
						&& ($tokens[$i + 1][Lexer::TYPE_OFFSET] ?? null) === Lexer::TOKEN_CLOSE_CURLY_BRACKET
					) {
						$renamed[$i] = $canonical;
					}
				}
			}
		}

		$fix = true;
		foreach ($renamed as $i => $canonical) {
			$fix = $context->report($token, "Annotation `{$tokens[$i][Lexer::VALUE_OFFSET]}` must be written `$canonical`.", trivia: $trivia) && $fix;
		}

		if ($renamed && $fix) {
			// written token by token, the printer of the tree would drop the ` * ` of the lines of a changed text
			$text = '';
			foreach ($tokens as $i => [$tokenText]) {
				$text .= $renamed[$i] ?? $tokenText;
			}

			$token->replaceTrivia($trivia, $trivia->withText($text));
		}
	}


	/** @return array<string, string>  lowercased name with the @ => canonical name */
	private static function getCanonicalNames(): array
	{
		static $names = null;
		if ($names === null) {
			$all = [...self::Standard, ...self::PhpUnit, ...self::StaticAnalysis];
			foreach (self::StaticAnalysis as $name) {
				foreach (self::StaticAnalysisPrefixes as $prefix) {
					$all[] = "$prefix-$name";
				}
			}

			$names = [];
			foreach ($all as $name) {
				$names[strtolower("@$name")] = "@$name";
			}
		}

		return $names;
	}
}
