<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{ConfigurableRule, NodeRule, RuleContext, RuleInfo, Stage};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\{Indentation, Node, Token, TokenKind};
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringPartNode};
use function strlen;


/**
 * The body and the closing marker of a heredoc or nowdoc are indented relative to the line where it starts:
 * one level deeper, or the same. Lines of the body indented further keep their extra indentation.
 */
#[RuleInfo(
	'dresscode/heredoc-indentation',
	Stage::Formatting,
	description: 'Indents the body and the closing marker of a heredoc relative to its starting line',
)]
final class HeredocIndentationRule extends NodeRule implements ConfigurableRule
{
	private bool $deeper = true;


	public static function getOptionsSchema(): Schema
	{
		return Expect::structure([
			'indentation' => Expect::anyOf('startPlusOne', 'sameAsStart')->default('startPlusOne')
				->description('startPlusOne indents the body one level deeper than the line the heredoc starts on, sameAsStart the same'),
		]);
	}


	public function configure(array $options): void
	{
		$this->deeper = $options['indentation'] === 'startPlusOne';
	}


	public function getVisitedTypes(): array
	{
		return [HeredocNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof HeredocNode) {
			return;
		}

		$style = $context->getStyle();
		$current = $node->indentation;
		$indentation = Indentation::normalize(self::getLineIndentation($node->openDelimiter), $style->toPhpSyntax()) . ($this->deeper ? $style->indent : '');
		if (
			$current === $indentation
			|| !$context->report($node->openDelimiter, 'Wrong indentation of the heredoc body and its closing marker')
		) {
			return;
		}

		$lineStart = true;
		foreach ($node->parts->getItems() as $part) {
			if ($part instanceof InterpolatedStringPartNode) {
				$text = $part->token->text;
				if ($lineStart && str_starts_with($text, $current) && !preg_match('~^[\r\n]~', $text)) {
					$text = $indentation . substr($text, strlen($current));
				}

				$text = (string) preg_replace('~(?<=\n)' . preg_quote($current, '~') . '(?![\r\n]|$)~', $indentation, $text);
				$part->token->setText($text);
			}

			$lineStart = str_ends_with((string) $part, "\n");
		}

		$node->closeDelimiter->setText($indentation . substr($node->closeDelimiter->text, strlen($current)));
	}


	/**
	 * Indentation of the line of the token; a line starting with the closing marker of another heredoc carries
	 * it in the marker.
	 */
	private static function getLineIndentation(Token $token): string
	{
		$start = $token;
		while (!$start->startsLine() && ($previous = $start->getPrevious()) !== null) {
			$start = $previous;
		}

		return $start->kind === TokenKind::EndHeredoc && preg_match('~^[ \t]*~', $start->text, $m)
			? $m[0]
			: $start->getIndentation();
	}
}
