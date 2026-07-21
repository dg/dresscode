<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Literals;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token};
use PhpSyntax\Nodes\Scalar\{HeredocNode, InterpolatedStringPartNode};


/**
 * Nowdoc for a heredoc that interpolates nothing and needs no escapes beyond `\\` and `\$`,
 * which it unescapes on the way.
 */
#[RuleInfo(
	'dresscode/nowdoc-without-interpolation',
	Stage::Structure,
	description: 'Uses nowdoc where a heredoc interpolates nothing',
)]
final class NowdocWithoutInterpolationRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [HeredocNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof HeredocNode
			|| $node->isNowdoc()
			|| $node->hasInterpolation()
			|| preg_match('~^<<<(\s*)"?([^"\'\s]+)"?(\R)$~', $node->openDelimiter->text, $match) !== 1
		) {
			return;
		}

		/** @var list<InterpolatedStringPartNode> $parts  the heredoc does not interpolate */
		$parts = $node->parts->getItems();
		$content = implode('', array_map(fn($part) => $part->token->text, $parts));
		$rest = strtr($content, ['\\\\' => '', '\$' => '']); // the unescapable pairs
		if (
			str_contains($rest, '\\')
			|| !$context->report($node, 'A heredoc without interpolation must be a nowdoc')
		) {
			return;
		}

		$node->openDelimiter->setText('<<<' . $match[1] . "'" . $match[2] . "'" . $match[3]);
		foreach ($parts as $part) {
			// in one pass: the backslash an escaped backslash leaves must not escape the dollar after it
			$unescaped = strtr($part->token->text, ['\\\\' => '\\', '\$' => '$']);
			if ($unescaped !== $part->token->text) {
				$part->token->setText($unescaped);
			}
		}
	}
}
