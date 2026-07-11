<?php declare(strict_types=1);

namespace DressCode\Rules\Files;

use DressCode\Rule;
use DressCode\RuleContext;
use DressCode\RuleInfo;
use DressCode\Stage;
use PhpSyntax\Nodes\Statement\InlineHtmlNode;


/**
 * UTF-8 without a byte order mark: the BOM, which PHP would output as inline HTML, is removed.
 */
#[RuleInfo(
	'dresscode/no-byte-order-mark',
	Stage::Structure,
	description: 'Removes the UTF-8 byte order mark',
)]
final class NoByteOrderMarkRule extends Rule
{
	private const Bom = "\xEF\xBB\xBF";


	public function getVisitedTypes(): array
	{
		return [];
	}


	public function beforeFile(RuleContext $context): void
	{
		$first = $context->getFile()->stmts->getItems()[0] ?? null;
		if (
			!$first instanceof InlineHtmlNode
			|| !str_starts_with($first->html->text, self::Bom)
			|| !$context->report($first, 'The file must not start with a byte order mark')
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
