<?php declare(strict_types=1);

namespace PhpSyntax\Nodes\Statement;


/**
 * Queries of InlineHtmlNode.
 */
trait InlineHtmlQueries
{
	/**
	 * Whether the text is only what may precede the code of a pure PHP file: a byte order mark, a hashbang
	 * line, or both.
	 */
	public function isPreamble(): bool
	{
		return preg_match("~^(\xEF\xBB\xBF)?(#![^\r\n]*\\R)?$~", $this->html->text) === 1;
	}
}
