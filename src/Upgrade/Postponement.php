<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Upgrade;

use DressCode\ConfigurationException;
use DressCode\Engine\{Helpers, Suppression};
use Nette\Utils\FileSystem;


/**
 * A comment `dresscode:ignore` about an upgrade that gives no reason after ` -- `, which only puts a task off.
 * @internal
 */
final readonly class Postponement
{
	public function __construct(
		/** relative to the root */
		public string $path,
		public int $line,
		public string $comment,
	) {
	}


	/**
	 * The postponements in the files of the project, within the paths given or all of them.
	 * @param  list<string>  $paths
	 * @return list<self>
	 * @throws ConfigurationException
	 */
	public static function findIn(Project $project, array $paths = []): array
	{
		$found = [];
		foreach ($project->findUpgradeFiles($paths)->files as $path) {
			$code = FileSystem::read(Helpers::toAbsolutePath($path, $project->root));
			foreach (@\PhpToken::tokenize($code) as $token) { // @ the lexer warns of the code, which is the check's to report
				$directive = $token->is([T_COMMENT, T_DOC_COMMENT]) ? Suppression::findDirective($token->text) : null;
				if (
					$directive !== null
					&& $directive['kind'] === 'ignore'
					&& str_starts_with($directive['text'], 'dresscode:')
					&& $directive['reason'] === ''
					&& self::namesUpgrade($directive['names'])
				) {
					$line = $token->line + substr_count($token->text, "\n", 0, $directive['offset']);
					$found[] = new self($path, $line, $directive['text']);
				}
			}
		}

		return $found;
	}


	/** Whether a name of the list is about an upgrade: a decision of upgrading, a package of upgrading data, a library, PHP. */
	private static function namesUpgrade(string $names): bool
	{
		return array_any(
			preg_split('~[,\s]+~', $names, -1, PREG_SPLIT_NO_EMPTY) ?: [],
			fn(string $name) => str_starts_with($name, 'upgrading')
				|| str_starts_with($name, 'upgrade-')
				|| $name === 'php'
				|| str_starts_with($name, 'php.')
				|| str_contains($name, '/'),
		);
	}
}
