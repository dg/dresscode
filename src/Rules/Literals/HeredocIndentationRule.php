<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{Decision, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Words;
use DressCode\Rules\NodeHelpers;
use PhpSyntax\{Indentation, Node, Token};
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringPartNode};
use function strlen;


/**
 * The body and the closing marker of a heredoc or nowdoc are indented relative to the line where it starts:
 * one level deeper, or the same. Lines of the body indented further keep their extra indentation.
 */
#[RuleInfo(Stage::Formatting)]
final class HeredocIndentationRule extends NodeRule
{
	private bool $deeper = true;


	public static function getDecisions(): array
	{
		return [new Decision('indentation.heredoc', new Words([
			'startPlusOne' => 'one level deeper than the line the heredoc starts on',
			'sameAsStart' => 'at the level of the line the heredoc starts on',
		]), 'The indentation of the body and the closing marker of a heredoc or nowdoc, lines of the body indented further keeping their extra indentation')];
	}


	public function configure(Values $values): void
	{
		$this->deeper = $values->get('indentation.heredoc')->getWord() === 'startPlusOne';
	}


	public function getVisitedNodes(): array
	{
		return [HeredocNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof HeredocNode) {
			return;
		}

		$style = $context->style;
		$current = $node->indentation;
		$indentation = Indentation::normalize($node->openDelimiter->getLineIndentation(), $style->toPhpSyntax()) . ($this->deeper ? $style->indent : '');
		$parts = $node->parts->getItems();
		// a body starting with an interpolation has no text to carry the indentation of its first line
		$fixable = !$parts || $parts[0] instanceof InterpolatedStringPartNode;
		if (
			$current === $indentation
			|| !$context->report(
				$node->openDelimiter,
				'Expected the heredoc body and its closing marker indented by ' . NodeHelpers::describeWidth($indentation) . ', ' . NodeHelpers::describeWidth($current) . ' found.',
				fixable: $fixable,
			)
			|| !$fixable
		) {
			return;
		}

		$lineStart = true;
		foreach ($parts as $i => $part) {
			if ($part instanceof InterpolatedStringPartNode) {
				$text = $part->token->text;
				if ($lineStart && str_starts_with($text, $current) && !preg_match('~^[\r\n]~', $text)) {
					$text = $indentation . substr($text, strlen($current));
				}

				// the end of a part before an interpolation is the start of a line that goes on, the end of the last one
				// is where the closing marker stands
				$end = $i === array_key_last($parts) ? '|$' : '';
				$text = (string) preg_replace('~(?<=\n)' . preg_quote($current, '~') . "(?![\\r\\n]$end)~", $indentation, $text);
				$part->token->setText($text);
			}

			$lineStart = str_ends_with((string) $part, "\n");
		}

		$node->closeDelimiter->setText($indentation . substr($node->closeDelimiter->text, strlen($current)));
	}
}
