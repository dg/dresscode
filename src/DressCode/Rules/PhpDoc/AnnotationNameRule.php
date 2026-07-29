<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine\DoctrineTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Known annotations are written in their canonical case: `@inheritDoc`, `@dataProvider`, `@phpstan-return`.
 * An annotation whose name is an imported class (a Doctrine annotation, for instance) is left alone.
 */
#[RuleInfo(
	'dresscode/annotation-name',
	Stage::Structure,
	description: 'Writes known annotations in their canonical case',
	modifiesComments: true,
)]
final class AnnotationNameRule extends Rule
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


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach ([...$node->leadingTrivia, ...$node->trailingTrivia] as $trivia) {
			if ($trivia->kind === TriviaKind::DocComment && !$trivia->inInterpolation) {
				$this->processDocComment($node, $trivia, $context);
			}
		}
	}


	private function processDocComment(Token $token, Trivia $trivia, RuleContext $context): void
	{
		$names = self::getCanonicalNames();
		$imports = $context->getAnalysis(NameResolver::class)->getClassImports($token);
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($trivia);
		$changed = false;
		$fix = true;
		foreach ($tree->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				$canonical = $names[strtolower($child->name)] ?? null;
				if (
					$canonical === null
					|| $canonical === $child->name
					|| isset($imports[strtolower(substr($child->name, 1))])
					// a Doctrine annotation is a class name and its value repeats it, so renaming would not show
					|| $child->value instanceof DoctrineTagValueNode
				) {
					continue;
				}

				$fix = $context->report($token, "Annotation $child->name must be written '$canonical'", trivia: $trivia) && $fix;
				$child->name = $canonical;
				$changed = true;
			} elseif ($child instanceof PhpDocTextNode) {
				$text = preg_replace_callback(
					'~\{(@[\w-]+)\}~',
					function (array $m) use ($names, &$fix, &$changed, $token, $trivia, $context): string {
						$canonical = $names[strtolower($m[1])] ?? $m[1];
						if ($canonical !== $m[1]) {
							$fix = $context->report($token, "Annotation $m[1] must be written '$canonical'", trivia: $trivia) && $fix;
							$changed = true;
						}

						return '{' . $canonical . '}';
					},
					$child->text,
				);
				$child->text = (string) $text;
			}
		}

		if ($changed && $fix) {
			$token->replaceTrivia($trivia, $phpDoc->print($tree, $trivia));
		}
	}


	/** @return array<string, string>  lowercased name with the @ → canonical name */
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
