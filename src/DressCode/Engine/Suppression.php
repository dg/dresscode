<?php declare(strict_types=1);

namespace DressCode\Engine;

use PhpSyntax\Node;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Token;
use PhpSyntax\Trivia;
use PhpSyntax\TriviaKind;
use function count;


/**
 * Which rules are silenced on which original lines, read once from the comments of the file before any mutation:
 * "dresscode:ignore [names]" on a line silences that line, on its own line the nearest node starting on the
 * next line; "dresscode:disable [names]" up to "dresscode:enable"; "dresscode:ignore-file" the whole file.
 * The phpcs forms (phpcs:ignore, phpcs:disable, phpcs:enable, @phpcsSuppress) are understood through aliases.
 * @internal
 */
final class Suppression
{
	private const All = '*';

	/** @var array<string, list<array{int, int}>>  rule name (or *) → line ranges */
	private array $ranges = [];


	/** @param \Closure(string): ?string $resolveAlias  maps a rule name or an alias to the canonical name, null if unknown */
	public static function fromFile(FileNode $file, \Closure $resolveAlias): self
	{
		$suppression = new self;
		$disabled = [];
		$lastLine = $file->eof->originalLine ?? 1;
		foreach ($file->getIndex()->getTokens() as $token) {
			foreach ([$token->leadingTrivia, $token->trailingTrivia] as $trivias) {
				foreach ($trivias as $index => $trivia) {
					if (
						!$trivia->isComment()
						|| !preg_match('~(?:dresscode|phpcs):(ignore-file|ignore|disable|enable)(?:\s+([\w/.,\s-]+?))?\s*(?:\*/|$)~m', $trivia->text, $m)
					) {
						continue;
					}

					$line = self::lineOf($trivia, $trivias, $index, $token);
					$names = self::names($m[2] ?? '', $resolveAlias);
					if ($m[1] === 'ignore-file') {
						$suppression->add([self::All], 1, PHP_INT_MAX);
					} elseif ($m[1] === 'ignore') {
						$ownLine = self::isAlone($trivia, $trivias, $index, $token);
						$target = $ownLine ? self::findNodeStartingAt($file, $line + 1) : null;
						$suppression->add($names, $target ? $line + 1 : $line, $target ? self::endLine($target) : ($ownLine ? $line + 1 : $line));
					} elseif ($m[1] === 'disable') {
						foreach ($names as $name) {
							$disabled[$name] = $line;
						}
					} else {
						foreach ($names === [self::All] ? array_keys($disabled) : $names as $name) {
							if (isset($disabled[$name])) {
								$suppression->add([$name], $disabled[$name], $line - 1);
								unset($disabled[$name]);
							}
						}
					}
				}
			}
		}

		foreach ($disabled as $name => $from) {
			$suppression->add([$name], $from, $lastLine);
		}

		$suppression->collectPhpcsSuppress($file, $resolveAlias);
		return $suppression;
	}


	public function isSuppressed(string $ruleName, int $line): bool
	{
		foreach ([$ruleName, self::All] as $name) {
			foreach ($this->ranges[$name] ?? [] as [$from, $to]) {
				if ($line >= $from && $line <= $to) {
					return true;
				}
			}
		}

		return false;
	}


	/** @param list<string> $names */
	private function add(array $names, int $from, int $to): void
	{
		foreach ($names as $name) {
			$this->ranges[$name][] = [$from, $to];
		}
	}


	/**
	 * @param  \Closure(string): ?string  $resolveAlias
	 * @return list<string>
	 */
	private static function names(string $list, \Closure $resolveAlias): array
	{
		$names = [];
		foreach (preg_split('~[,\s]+~', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $name) {
			$names[] = $resolveAlias($name) ?? $name;
		}

		return $names ?: [self::All];
	}


	/**
	 * @param \Closure(string): ?string $resolveAlias
	 */
	private function collectPhpcsSuppress(FileNode $file, \Closure $resolveAlias): void
	{
		foreach ($file->getDescendants() as $node) {
			$doc = $node->getDocComment();
			if ($doc && preg_match_all('~@phpcsSuppress\s+([\w.]+)~', $doc->text, $m)) {
				$from = $node->getFirstToken()?->originalLine;
				if ($from !== null) {
					$this->add(self::names(implode(',', $m[1]), $resolveAlias), $from, self::endLine($node));
				}
			}
		}
	}


	/** @param list<Trivia> $trivias */
	private static function lineOf(Trivia $trivia, array $trivias, int $index, Token $token): int
	{
		$line = $token->originalLine ?? 1;
		if ($trivias === $token->leadingTrivia) {
			for ($i = count($trivias) - 1; $i > $index; $i--) {
				$line -= preg_match_all('~\r\n|\r|\n~', $trivias[$i]->text);
			}

			return $line - preg_match_all('~\r\n|\r|\n~', $trivia->text);
		}

		for ($i = 0; $i < $index; $i++) {
			$line += preg_match_all('~\r\n|\r|\n~', $trivias[$i]->text);
		}

		return $line + preg_match_all('~\r\n|\r|\n~', $token->text);
	}


	/**
	 * Whether the comment has a line of its own.
	 * @param list<Trivia> $trivias
	 */
	private static function isAlone(Trivia $trivia, array $trivias, int $index, Token $token): bool
	{
		if ($trivias !== $token->leadingTrivia) {
			return false;
		}

		for ($i = $index - 1; $i >= 0; $i--) {
			if ($trivias[$i]->isEndOfLine()) {
				break;
			} elseif ($trivias[$i]->kind !== TriviaKind::Whitespace) {
				return false;
			}

			if ($i === 0) {
				$previous = $token->getPrevious();
				$before = $previous->trailingTrivia ?? [];
				if ($previous && (!$before || !end($before)->isEndOfLine())) {
					return false;
				}
			}
		}

		for ($i = $index + 1; $i < count($trivias); $i++) {
			if ($trivias[$i]->isEndOfLine()) {
				return true;
			} elseif ($trivias[$i]->kind !== TriviaKind::Whitespace) {
				return false;
			}
		}

		return false;
	}


	/** The outermost node whose first token starts at the line. */
	private static function findNodeStartingAt(FileNode $file, int $line): ?Node
	{
		foreach ($file->getDescendants() as $node) {
			if ($node->getFirstToken()?->originalLine === $line) {
				return $node;
			}
		}

		return null;
	}


	private static function endLine(Node $node): int
	{
		$token = $node->getLastToken();
		return $token === null || $token->originalLine === null
			? PHP_INT_MAX
			: $token->originalLine + preg_match_all('~\r\n|\r|\n~', $token->text);
	}
}
