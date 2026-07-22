<?php declare(strict_types=1);

namespace DressCode\Rules\Literals;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Node;
use PhpSyntax\Nodes\Scalar\HeredocNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringPartNode;
use PhpSyntax\Token;


/**
 * Nowdoc for a heredoc that interpolates nothing and needs no escapes beyond `\\` and `\$`,
 * which it unescapes on the way.
 */
#[RuleInfo(
	'dresscode/nowdoc-without-interpolation',
	Stage::Structure,
	description: 'Uses nowdoc where a heredoc interpolates nothing',
)]
final class NowdocWithoutInterpolationRule extends Rule
{
	public function getVisitedTypes(): array
	{
		return [HeredocNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof HeredocNode
			|| preg_match('~^<<<(\s*)"?([^"\'\s]+)"?(\R)$~', $node->openDelimiter->text, $match) !== 1
		) {
			return;
		}

		$parts = [];
		foreach ($node->parts->getItems() as $part) {
			if (!$part instanceof InterpolatedStringPartNode) {
				return; // interpolation
			}

			$parts[] = $part;
		}

		$content = implode('', array_map(fn($part) => $part->text->text, $parts));
		$rest = str_replace(['\\\\', '\$'], '', $content); // the unescapable pairs
		if (
			str_contains($rest, '\\')
			|| !$context->report($node, 'A heredoc without interpolation must be a nowdoc')
		) {
			return;
		}

		$node->openDelimiter->setText('<<<' . $match[1] . "'" . $match[2] . "'" . $match[3]);
		foreach ($parts as $part) {
			$unescaped = str_replace(['\\\\', '\$'], ['\\', '$'], $part->text->text);
			if ($unescaped !== $part->text->text) {
				$part->text->setText($unescaped);
			}
		}
	}
}
