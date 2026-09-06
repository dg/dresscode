<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rules;


/**
 * The Nette Coding Standard: PER with tabs, where it departs from PER (the opening tag carries the declare,
 * two blank lines between methods, constants in PascalCase, the first link of a chain and several items of
 * an array may share a line, the brace of a multi-line signature goes below a return type) and with the rules
 * about imports, names, PHPDoc and modern syntax the Nette libraries follow.
 */
#[PresetInfo('dresscode/nette', 'Nette Coding Standard', indent: 'tab', eol: 'majority')]
final class Nette implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// the file: the declare on the line of the opening tag, no invisible characters
			Rules\Files\StrictTypesRequiredRule::class => ['placement' => 'openingTagLine'],
			Rules\Files\NoInvisibleCharactersRule::class => true,

			// the header: imports in one block, one blank line between the blocks and before a statement that follows
			// them, two before a declaration
			Rules\Files\HeaderBlankLinesRule::class => [
				'beforeNamespace' => 1, 'afterOpeningTag' => null, 'afterNamespace' => 1, 'afterImports' => 1, 'betweenImportGroups' => 0, 'beforeDeclaration' => 2,
			],
			Rules\Namespaces\OrderedImportsRule::class => true,
			Rules\Namespaces\UnusedImportsRule::class => true,
			Rules\Namespaces\ReferenceUsedNamesOnlyRule::class => true,
			Rules\Namespaces\ImportNotationRule::class => ['functions' => 'combined', 'constants' => 'combined', 'groupUse' => 'keep'],
			Rules\Namespaces\UselessAliasRule::class => true,
			Rules\Namespaces\UseFromSameNamespaceRule::class => true,
			Rules\Namespaces\ClassReferenceNameCasingRule::class => true,
			Rules\Namespaces\NoLeadingBackslashInGlobalNamespaceRule::class => true,

			// blank lines: two between methods, none inside a body's braces
			Rules\Whitespace\DeclarationBlankLinesRule::class => true,
			Rules\Whitespace\BodyBlankLinesRule::class => true,

			// the whitespace of a line: exactly one space around a ternary, a tab may align commas, a space after the
			// slashes of a comment
			Rules\Expressions\TernaryOperatorSpacingRule::class => ['spacing' => 'single'],
			Rules\Comments\CommentSpacingRule::class => true,
			Rules\Whitespace\SemicolonSpacingRule::class => true,
			Rules\Whitespace\CommaSpacingRule::class => true,
			Rules\Expressions\ObjectOperatorSpacingRule::class => true,
			Rules\Expressions\DoubleColonSpacingRule::class => true,
			Rules\Arrays\ArraySpacingRule::class => true,
			Rules\Expressions\OffsetBracketSpacingRule::class => true,
			Rules\Classes\ClassDefinitionSpacingRule::class => true,

			// breaks: the brace of a multi-line signature below its return type, promoted properties on lines of their
			// own, the first link of a chain and several items of an array may share a line
			Rules\Whitespace\BracesPositionRule::class => ['multiLineParameters' => 'nextLineAfterReturnType'],
			Rules\Functions\MultiLineSignatureRule::class => true,
			Rules\Expressions\MultiLineChainRule::class => ['leadingLinksOnFirstLine' => true],
			Rules\Arrays\MultiLineArrayRule::class => ['oneItemPerLine' => false],
			Rules\Arrays\TrailingCommaRule::class => ['multiLine' => ['arrays', 'arguments', 'parameters']],

			// names and declarations
			Rules\Classes\NameCasingRule::class => [
				'classes' => 'PascalCase', 'methods' => 'camelCase', 'functions' => 'camelCase', 'constants' => 'PascalCase',
				'enumCases' => 'PascalCase', 'properties' => 'camelCase', 'variables' => 'camelCase',
			],
			Rules\Classes\OrderedMembersRule::class => true,
			Rules\Classes\UselessModifierRule::class => true,
			Rules\Classes\UselessNullPropertyInitializationRule::class => true,
			Rules\Classes\SelfForCurrentClassRule::class => true,
			Rules\Classes\ModernClassNameReferenceRule::class => ['onObjects' => true],
			Rules\Classes\NoThisInStaticContextRule::class => true,
			Rules\Expressions\NewArgumentParenthesesRule::class => ['namedClasses' => 'forbidden', 'anonymousClasses' => 'forbidden'],
			Rules\Types\NullableTypeForDefaultNullRule::class => true,
			Rules\Functions\UselessParameterDefaultRule::class => true,
			Rules\Functions\NoInnerFunctionsRule::class => true,
			Rules\Variables\NoGlobalKeywordRule::class => true,

			// expressions and literals
			Rules\Expressions\NotEqualsOperatorRule::class => true,
			Rules\Expressions\NoYodaComparisonRule::class => true,
			Rules\Expressions\ExplicitOperatorPrecedenceRule::class => true,
			Rules\Expressions\UselessParenthesesAroundNewRule::class => true,
			Rules\Expressions\NoShortBoolCastRule::class => true,
			Rules\Expressions\IncrementOperatorRule::class => true,
			Rules\Expressions\CombinedAssignmentOperatorRule::class => true,
			Rules\Expressions\SymbolicLogicalOperatorsRule::class => true,
			Rules\Expressions\ShortTernaryOperatorRule::class => true,
			Rules\Expressions\UselessTernaryOperatorRule::class => true,
			Rules\Expressions\NullCoalescingOperatorRule::class => true,
			Rules\Literals\MagicConstantCasingRule::class => true,
			Rules\Literals\SingleQuotedStringsRule::class => true,
			Rules\Literals\NoTrailingWhitespaceInStringRule::class => true,
			Rules\Literals\ComplexStringVariableRule::class => true,
			Rules\Literals\NoImplicitBackslashRule::class => true,
			Rules\Literals\NoBacktickOperatorRule::class => true,
			Rules\Literals\UselessStringConcatRule::class => true,
			Rules\Literals\OctalNotationRule::class => true,
			Rules\Literals\NumericLiteralSeparatorRule::class => ['minDigitsBeforeDecimalPoint' => 7, 'minDigitsAfterDecimalPoint' => 20],
			Rules\Variables\CombinedUnsetsRule::class => true,
			Rules\Variables\CombinedIssetsRule::class => true,
			Rules\Variables\NoDuplicateAssignmentRule::class => true,

			// control flow: a fall-through in a switch says "break omitted"
			Rules\ControlFlow\NoEmptyStatementRule::class => true,
			Rules\ControlFlow\UselessConstructParenthesesRule::class => true,
			Rules\ControlFlow\UselessBracesRule::class => true,
			Rules\ControlFlow\UselessReturnRule::class => true,
			Rules\ControlFlow\UselessCatchVariableRule::class => true,
			Rules\ControlFlow\UselessIfConditionWithReturnRule::class => true,
			Rules\ControlFlow\FallThroughCommentRule::class => ['comment' => 'break omitted'],
			Rules\ControlFlow\NoAlternativeSyntaxRule::class => true,
			Rules\ControlFlow\NoContinueInSwitchRule::class => true,
			Rules\ControlFlow\NoUnreachableCatchRule::class => true,
			Rules\ControlFlow\ReferenceThrowableOnlyRule::class => true,

			// functions
			Rules\Functions\NativeFunctionCasingRule::class => true,
			Rules\Functions\ArrowFunctionRule::class => true,
			Rules\Functions\StrictCallRule::class => true,
			Rules\Functions\NoIsNullRule::class => true,
			Rules\Functions\NoConversionFunctionsRule::class => true,
			Rules\Functions\NoDirnameOfFileRule::class => true,
			Rules\Functions\NoAliasFunctionsRule::class => true,
			Rules\Functions\NoSettypeRule::class => true,
			Rules\Functions\NoDeprecatedFunctionsRule::class => true,
			Rules\Functions\NoUnpackingInOptimizedCallRule::class => true,

			// comments and PHPDoc: both notations of an array type stay as they are
			Rules\Comments\NoEmptyCommentRule::class => true,
			Rules\Comments\NoHashCommentRule::class => true,
			Rules\Comments\CommentedOutFunctionRule::class => ['functions' => ['print_r', 'var_dump', 'var_export', 'dump']],
			Rules\PhpDoc\NoEmptyPhpDocRule::class => true,
			Rules\PhpDoc\PhpDocTrimRule::class => true,
			Rules\PhpDoc\PhpDocCanonicalTypesRule::class => ['arrayNotation' => null],
			Rules\PhpDoc\PhpDocNullLastRule::class => true,
			Rules\PhpDoc\PhpDocAlignmentRule::class => true,
			Rules\PhpDoc\AnnotationNameRule::class => true,
			Rules\PhpDoc\ExplicitAssertionRule::class => true,
			Rules\PhpDoc\UselessInheritDocRule::class => true,
			Rules\PhpDoc\UselessFunctionPhpDocRule::class => true,
			Rules\PhpDoc\UselessConstantVarAnnotationRule::class => true,
			Rules\PhpDoc\PropertyPhpDocSingleLineRule::class => true,
			Rules\PhpDoc\PropertyPhpDocRequiredRule::class => true,
			Rules\PhpDoc\PropertyVarAnnotationRule::class => true,
			Rules\PhpDoc\PromotedPropertyAnnotationPositionRule::class => true,
			Rules\PhpDoc\NoEmptyVarAnnotationRule::class => true,
			Rules\PhpDoc\NoDuplicateReturnAnnotationRule::class => true,
			Rules\PhpDoc\NoUnknownParamAnnotationRule::class => true,
			Rules\PhpDoc\ForbiddenAnnotationsRule::class => [
				'annotations' => ['@access', '@author', '@copyright', '@created', '@license', '@package', '@since', '@subpackage', '@todo', '@version'],
			],
			Rules\PhpDoc\ForbiddenPhpDocLinesRule::class => [
				'patterns' => ['~^(?:(?!private|protected|static)\S+ )?(?:con|de)structor\.\z~i', '~^Created by \S+\.\z~i', '~^\S+ [gs]etter\.\z~i'],
			],
		];
	}


	public function getParents(): array
	{
		return [Per::class];
	}
}
