<?php declare(strict_types=1);

namespace DressCode\Tools;

use DressCode\Config;


/**
 * Leaves generated files out of the check: their style is decided by whatever wrote them,
 * so a violation there is a report about the generator and belongs to it.
 * Usage in dresscode.neon: `extensions: - DressCode\Tools\SkipGenerated`.
 */
final class SkipGenerated
{
	public function __invoke(Config $config): void
	{
		$config->skipWhen(fn(string $content): bool => (bool) preg_match(
			'~^<\?php.{0,50}/\*[\s*]*@generated~As',
			$content,
		));
	}
}
