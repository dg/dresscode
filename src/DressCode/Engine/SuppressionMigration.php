<?php declare(strict_types=1);

namespace DressCode\Engine;

use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Trivia;


/**
 * Rewrites the phpcs suppression comments of a file to the dresscode form with canonical rule names:
 * `phpcs:ignore|disable|enable|ignoreFile` become `dresscode:ignore|disable|enable|ignore-file`; a `@phpcsSuppress`
 * tag keeps its name and gets the canonical rule name. Names no rule covers stay as they are and are listed.
 * @internal
 */
final class SuppressionMigration
{
	public int $count = 0;

	/** @var array<string, true> */
	public array $unknownNames = [];

	/** an ignore on a line of its own was migrated; its scope grows from the next line to the whole next statement */
	public bool $ownLineIgnore = false;


	/** @param \Closure(string): list<string> $resolveNames */
	public function __construct(
		private readonly \Closure $resolveNames,
	) {
	}


	/** Whether the file changed. */
	public function migrate(FileNode $file): bool
	{
		$changed = false;
		foreach ($file->getIndex()->getTokens() as $token) {
			foreach ([$token->leadingTrivia, $token->trailingTrivia] as $ownLine => $trivias) {
				foreach ($trivias as $trivia) {
					$text = $trivia->isComment() ? $this->rewrite($trivia->text, ownLine: $ownLine === 0) : $trivia->text;
					if ($text !== $trivia->text) {
						$token->replaceTrivia($trivia, new Trivia($trivia->kind, $text, $trivia->inInterpolation, $trivia->originalLine));
						$changed = true;
					}
				}
			}
		}

		return $changed;
	}


	private function rewrite(string $text, bool $ownLine): string
	{
		$text = (string) preg_replace_callback(
			'~phpcs:(ignoreFile|ignore-file|ignore|disable|enable)((?:[ \t]+[\w/][\w/.-]*(?:[ \t]*,[ \t]*[\w/][\w/.-]*)*)?)~',
			function (array $m) use ($ownLine): string {
				$this->count++;
				$kind = $m[1] === 'ignoreFile' ? 'ignore-file' : $m[1];
				$this->ownLineIgnore = $this->ownLineIgnore || ($kind === 'ignore' && $ownLine);
				$names = preg_split('~[,\s]+~', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
				return "dresscode:$kind" . ($names ? ' ' . implode(', ', array_map($this->canonical(...), $names)) : '');
			},
			$text,
		);
		return (string) preg_replace_callback(
			'~@phpcsSuppress[ \t]+([\w/][\w/.-]*)~',
			function (array $m): string {
				$name = $this->canonical($m[1]);
				if ($name !== $m[1]) {
					$this->count++;
				}

				return "@phpcsSuppress $name";
			},
			$text,
		);
	}


	private function canonical(string $name): string
	{
		$resolved = ($this->resolveNames)($name);
		if (!$resolved) {
			$this->unknownNames[$name] = true;
			return $name;
		}

		return implode(', ', $resolved);
	}
}
