<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Token, Trivia, TriviaKind};


/**
 * The long, lowercase `<?php` opening tag instead of `<?` or `<?PHP`; `<?=` stays.
 */
#[RuleInfo(
	'dresscode/full-opening-tag',
	Stage::Structure,
	description: 'Requires the <?php opening tag',
)]
final class FullOpeningTagRule extends NodeRule
{
	public function getVisitedTypes(): array
	{
		return [Token::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof Token) {
			return;
		}

		foreach ($node->leadingTrivia as $trivia) {
			if ($trivia->kind !== TriviaKind::OpenTag) {
				continue;
			}

			$fixed = (string) preg_replace('~^<\?(?:php)?(?=\s)~i', '<?php', $trivia->text);
			if ($fixed !== $trivia->text && $context->report($node, 'The opening tag must be <?php', trivia: $trivia)) {
				$node->replaceTrivia($trivia, new Trivia(TriviaKind::OpenTag, $fixed));
			}
		}
	}
}
