<?php declare(strict_types=1);

namespace DressCode\Presets;

use DressCode\Preset;
use DressCode\PresetContext;
use DressCode\PresetInfo;
use DressCode\Rules;


/**
 * PSR-12 Extended Coding Style, section by section; the specification defines the style and this preset
 * adds nothing of its own.
 */
#[PresetInfo('dresscode/psr12', 'PSR-12 Extended Coding Style', indent: '    ', eol: "\n")]
final class Psr12 implements Preset
{
	public function getRules(PresetContext $context): array
	{
		return [
			// 2. General: files, lines, indenting, keywords and types
			Rules\Files\NoByteOrderMarkRule::class => true,
			Rules\Files\FullOpeningTagRule::class => true,
			Rules\Files\LineEndingRule::class => true,
			Rules\Files\EofNewlineRule::class => true,
			Rules\Files\NoClosingTagRule::class => true,
			Rules\Files\NoTrailingWhitespaceRule::class => true,
			Rules\ControlFlow\SingleStatementPerLineRule::class => true,
			Rules\Whitespace\StatementIndentationRule::class => true,
			Rules\Literals\KeywordCasingRule::class => true,
			Rules\Literals\ConstantCasingRule::class => true,
			Rules\Expressions\CastSpacingRule::class => true,
			Rules\Expressions\CastCanonicalTypeRule::class => true,

			// 2.1 Basic coding standard: PSR-1 3 and 4 on the case of names
			Rules\Classes\NameCasingRule::class => ['classes' => 'PascalCase', 'methods' => 'camelCase', 'constants' => 'UPPER_CASE'],

			// 3. Declare statements, namespace and import statements
			Rules\Files\HeaderBlankLinesRule::class => true,
			Rules\Namespaces\OrderedImportsRule::class => ['alphabetically' => false],
			Rules\Namespaces\NoLeadingBackslashInImportRule::class => true,
			Rules\Files\DeclareSpacingRule::class => true,

			// 4. Classes, properties and methods
			Rules\Expressions\NewArgumentParenthesesRule::class => ['anonymousClasses' => null],
			Rules\Classes\ClassDefinitionSpacingRule::class => true,
			Rules\Whitespace\BracesPositionRule::class => ['allowSingleLineAnonymousFunctions' => false],
			Rules\Whitespace\DeclarationBlankLinesRule::class => [
				'betweenFunctions' => null, 'betweenFunctionsInInterface' => null,
				'betweenMembers' => null, 'beforeDocumentedMember' => null, 'afterPhpdoc' => null,
			],
			Rules\Classes\OrderedMembersRule::class => ['order' => ['use_trait']],
			Rules\Classes\VisibilityRequiredRule::class => true,
			Rules\Functions\FunctionNameSpacingRule::class => true,
			Rules\Whitespace\ParenthesesSpacingRule::class => true,
			Rules\Whitespace\CommaSpacingRule::class => ['tabAlignment' => false],
			Rules\Functions\MultiLineSignatureRule::class => ['promotedProperties' => false],
			Rules\Types\TypeHintSpacingRule::class => true,
			Rules\Expressions\ReferenceSpacingRule::class => true,
			Rules\Expressions\SpreadOperatorSpacingRule::class => true,
			Rules\Functions\MultiLineCallRule::class => true,

			// 5. Control structures
			Rules\Whitespace\ConstructSpacingRule::class => true,
			Rules\ControlFlow\ControlStructureBracesRule::class => true,
			Rules\ControlFlow\ElseifKeywordRule::class => true,
			Rules\ControlFlow\ContinuationPositionRule::class => true,
			Rules\ControlFlow\MultiLineConditionRule::class => true,
			Rules\ControlFlow\SwitchCaseColonRule::class => true,
			Rules\ControlFlow\SwitchCaseSpacingRule::class => true,
			Rules\ControlFlow\FallThroughCommentRule::class => true,

			// 6. Operators
			Rules\Expressions\UnaryOperatorSpacingRule::class => true,
			Rules\Expressions\BinaryOperatorSpacingRule::class => true,
			Rules\Expressions\TernaryOperatorSpacingRule::class => true,

			// 11. Arrays
			Rules\Arrays\ShortArraySyntaxRule::class => true,
			Rules\Arrays\ArrayIndentationRule::class => true,
		];
	}


	public function getParents(): array
	{
		return [];
	}
}
