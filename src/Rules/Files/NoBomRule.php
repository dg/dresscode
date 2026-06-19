<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Files;

use DressCode\{Decision, Domain, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\Nodes\Statement\InlineHtmlNode;


/**
 * UTF-8 without a byte order mark: the BOM, which PHP would output as inline HTML, is removed.
 */
#[RuleInfo(Stage::Structure)]
final class NoBomRule extends NodeRule
{
	private const Bom = "\xEF\xBB\xBF";


	public static function getDecisions(): array
	{
		return [new Decision('file.bom', Domain::state('forbidden'), 'The file starts without a byte order mark')];
	}


	public function getVisitedNodes(): array
	{
		return [];
	}


	public function beforePass(RuleContext $context): void
	{
		$first = $context->file->statements->getItems()[0] ?? null;
		if (
			!$first instanceof InlineHtmlNode
			|| !str_starts_with($first->html->text, self::Bom)
			|| !$context->report($first, 'The file must not start with a byte order mark.')
		) {
			return;
		}

		$text = substr($first->html->text, 3);
		if ($text === '') {
			$first->remove();
		} else {
			$first->html->setText($text);
		}
	}
}
