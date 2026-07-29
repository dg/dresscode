<?php declare(strict_types=1);

namespace DressCode\Rules\PhpDoc;

use DressCode\Analyses\PhpDoc;
use DressCode\ConfigurableRule;
use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PhpSyntax\Node;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;


/**
 * Lines of the description of a doc comment matching one of the configured patterns are removed
 * ("Class constructor.", "Created by PhpStorm."); a doc comment left empty goes away.
 */
#[RuleInfo(
	'dresscode/forbidden-phpdoc-lines',
	Stage::Structure,
	description: 'Removes description lines matching the configured patterns from doc comments',
	modifiesComments: true,
)]
final class ForbiddenPhpDocLinesRule extends Rule implements ConfigurableRule
{
	/** @var list<string> */
	private array $patterns = [];


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'patterns' => Expect::listOf('string')->description('Regular expressions matched against every line of a description'),
		]);
	}


	public function configure(array $options): void
	{
		$this->patterns = $options['patterns'];
	}


	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token || $this->patterns === []) {
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
		$phpDoc = $context->getAnalysis(PhpDoc::class);
		$tree = $phpDoc->parse($trivia);
		$prefix = preg_match('~\n([ \t]*\*)~', $trivia->text, $m) ? "\n$m[1] " : "\n";
		$changed = false;
		$fix = true;
		foreach ($tree->children as $child) {
			if ($child instanceof PhpDocTagNode) {
				break; // only the description before the tags
			} elseif (!$child instanceof PhpDocTextNode) {
				continue;
			}

			$lines = [];
			foreach (explode("\n", $child->text) as $line) {
				$line = trim($line);
				foreach ($this->patterns as $pattern) {
					if (preg_match($pattern, $line)) {
						$fix = $context->report($token, "Forbidden doc comment line '$line'", trivia: $trivia) && $fix;
						$line = trim((string) preg_replace($pattern, '', $line));
						$changed = true;
					}
				}

				if ($line !== '') {
					$lines[] = $line;
				}
			}

			$child->text = implode($prefix, $lines);
		}

		if (!$changed || !$fix) {
			return;
		}

		$children = $tree->children;
		while ($children && $children[0] instanceof PhpDocTextNode && trim($children[0]->text) === '') {
			array_shift($children);
		}

		$tree->children = array_values($children);
		if (PhpDoc::isEmpty($tree)) {
			$token->removeTrivia($trivia);
		} else {
			$token->replaceTrivia($trivia, $phpDoc->print($tree, $trivia));
		}
	}
}
