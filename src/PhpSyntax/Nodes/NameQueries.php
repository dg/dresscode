<?php declare(strict_types=1);

namespace PhpSyntax\Nodes;

use PhpSyntax\NameKind;
use PhpSyntax\TokenKind;


/**
 * Queries of NameNode.
 */
trait NameQueries
{
	/** The name as written, without trivia. */
	public function getName(): string
	{
		return $this->token->text;
	}


	public function getKind(): NameKind
	{
		return match ($this->token->kind) {
			TokenKind::NameFullyQualified => NameKind::FullyQualified,
			TokenKind::NameQualified => NameKind::Qualified,
			TokenKind::NameRelative => NameKind::Relative,
			default => NameKind::Unqualified,
		};
	}


	/**
	 * Segments of the name without the leading backslash or the "namespace" prefix.
	 * @return list<string>
	 */
	public function getParts(): array
	{
		$name = $this->token->text;
		$name = match ($this->getKind()) {
			NameKind::FullyQualified => substr($name, 1),
			NameKind::Relative => substr($name, strlen('namespace\\')),
			default => $name,
		};
		return explode('\\', $name);
	}


	/** Whether the name is a keyword the grammar accepts in place of a name (static, array, readonly, exit...). */
	public function isKeyword(): bool
	{
		return !in_array($this->token->kind, [TokenKind::Identifier, TokenKind::NameQualified, TokenKind::NameFullyQualified, TokenKind::NameRelative], strict: true);
	}
}
