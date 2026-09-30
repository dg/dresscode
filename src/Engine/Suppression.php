<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use PhpSyntax\{Node, Token, Trivia};
use PhpSyntax\Nodes\{FileNode, PlainNodeList, SeparatedNodeList};
use function count;


/**
 * Which rules are silenced on which original lines, read once from the comments of the file before any mutation:
 * the dresscode:ignore, disable, enable and ignoreFile comments, and the forms of phpcs, whose names Interop
 * translates, or which name ours. What follows ` -- ` says why, for the reader alone.
 * @internal
 */
final class Suppression
{
	private const All = '*';

	/** @var array<string, list<array{int, int}>>  a decision, a section or a structure (or *) => line ranges */
	private array $ranges = [];

	/** @var array<string, string>  a name of a comment that stands for nothing => the comment */
	private array $unknown = [];


	/**
	 * @param \Closure(string): list<string> $expandName  maps a name to the decisions it stands for, empty if unknown
	 * @param ?string $code  the source; when it mentions no suppression, the tokens are not walked at all
	 * @param array<string, list<string>> $comments  pattern of a comment => the decisions it silences where it stands
	 */
	public static function fromFile(FileNode $file, \Closure $expandName, ?string $code = null, array $comments = []): self
	{
		$suppression = new self;
		if ($code !== null && $comments === [] && !str_contains($code, 'dresscode:') && !str_contains($code, 'phpcs')) { // nothing to read
			return $suppression;
		}

		$disabled = [];
		$lastLine = max($file->endOfFile->line, 1);
		foreach ($file->getTokens() as $token) {
			foreach ([$token->leadingTrivia, $token->trailingTrivia] as $side => $trivias) {
				foreach ($trivias as $index => $trivia) {
					if (!$trivia->isComment()) {
						continue;
					} elseif ($trivia->id === Trivia::DocComment && str_contains($trivia->text, '@phpcsSuppress')) {
						$suppression->collectPhpcsSuppress(
							$trivia,
							$side === 0 ? $token->getPrevious() : $token,
							$side === 0 ? $token : $token->getNext(),
							$expandName,
						);
					}

					if (preg_match('~(?:dresscode|phpcs):(ignoreFile|ignore|disable|enable)(?:\s+([\w/.\\\\][\w/.\\\\,\s-]*?))?(?:\s+--(?:\s.*?)?)?(?=\s*(?:\*/|$))~m', $trivia->text, $m)) {
						$names = $suppression->expandNames($m[2] ?? '', $expandName, $m[0]);
					} elseif ($names = self::matchComment($trivia->text, $comments)) {
						$m = [1 => 'ignore'];
					} else {
						continue;
					}

					$line = $trivia->line;
					if ($m[1] === 'ignoreFile') {
						$suppression->add([self::All], 1, PHP_INT_MAX);
					} elseif ($m[1] === 'ignore') {
						$ownLine = self::isAlone($trivias, $index, $token);
						$next = $line + preg_match_all('~\r\n|\r|\n~', $trivia->text) + 1;
						$target = null;
						for ($t = $token; $ownLine && !$target && $t?->line === $next; $t = $t->getNext()) {
							$target = self::findOutermostNode($t);
						}

						$suppression->add($names, $target ? $next : $line, $target ? self::getEndLine($target) : ($ownLine ? $next : $line));

					} elseif ($m[1] === 'disable') {
						foreach ($names as $name) {
							$disabled[$name] ??= $line;
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

		return $suppression;
	}


	/** Whether the decision is silenced on the line: by its path, by a section or a structure above it, or by all. */
	public function isSilenced(string $decision, int $line): bool
	{
		if ($this->ranges === []) {
			return false;
		}

		$names = [self::All];
		$name = $decision;
		do {
			$names[] = $name;
			$pos = strrpos($name, '.');
			$name = $pos === false ? '' : substr($name, 0, $pos);
		} while ($name !== '');

		foreach ($names as $name) {
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
	 * What the names of a comment silence; a name of a `dresscode:` comment that stands for nothing is remembered with
	 * the comment, a name of another tool being that tool's business.
	 * @param  \Closure(string): list<string>  $expandName
	 * @return list<string>
	 */
	private function expandNames(string $list, \Closure $expandName, ?string $comment = null): array
	{
		$names = [];
		foreach (preg_split('~[,\s]+~', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $name) {
			$name = ltrim($name, '\\');
			$resolved = $expandName($name);
			if ($resolved === [] && $comment !== null && str_starts_with($comment, 'dresscode:')) {
				$this->unknown[$name] = trim($comment);
			}

			$names = [...$names, ...($resolved ?: [$name])];
		}

		return $names ?: [self::All];
	}


	/**
	 * The names `dresscode:` comments give that stand for no decision, section or rule, so that they silence nothing.
	 * @return array<string, string>  name => the comment
	 */
	public function getUnknownNames(): array
	{
		return $this->unknown;
	}


	/**
	 * The tags of a doc comment silence the node it documents: the one starting at the token after it, or one ending
	 * at the token before it, as a parameter does.
	 * @param \Closure(string): list<string> $expandName
	 */
	private function collectPhpcsSuppress(Trivia $doc, ?Token $before, ?Token $after, \Closure $expandName): void
	{
		$node = $after ? self::findOutermostNode($after) : null;
		if ($node?->getDocComment() !== $doc) {
			for ($node = $before?->parent; $node && $node->getLastToken() === $before && $node->getDocComment() !== $doc; $node = $node->parent);
		}

		$from = $node?->getFirstToken()?->line;
		if (
			$from !== null
			&& $node->getDocComment() === $doc
			&& preg_match_all('~@phpcsSuppress[ \t]+([\w/][\w/.-]*(?:[ \t]*,[ \t]*[\w/][\w/.-]*)*)~', $doc->text, $m)
		) {
			$this->add($this->expandNames(implode(',', $m[1]), $expandName), $from, self::getEndLine($node));
		}
	}


	/**
	 * @param  array<string, list<string>>  $comments
	 * @return list<string>
	 */
	private static function matchComment(string $text, array $comments): array
	{
		$names = [];
		foreach ($comments as $pattern => $rules) {
			if (preg_match($pattern, $text)) {
				$names = [...$names, ...$rules];
			}
		}

		return $names;
	}


	/**
	 * Whether the comment has a line of its own.
	 * @param list<Trivia> $trivias
	 */
	private static function isAlone(array $trivias, int $index, Token $token): bool
	{
		if ($trivias !== $token->leadingTrivia) {
			return false;
		}

		for ($i = $index - 1; $i >= 0; $i--) {
			if ($trivias[$i]->isLineEnding()) {
				break;
			} elseif ($trivias[$i]->id !== Trivia::Whitespace) {
				return false;
			}

			if ($i === 0) {
				$previous = $token->getPrevious();
				$before = $previous->trailingTrivia ?? [];
				if ($previous && (!$before || !end($before)->isLineEnding())) {
					return false;
				}
			}
		}

		for ($i = $index + 1; $i < count($trivias); $i++) {
			if ($trivias[$i]->isLineEnding()) {
				return true;
			} elseif ($trivias[$i]->id !== Trivia::Whitespace) {
				return false;
			}
		}

		return false;
	}


	/** The outermost node the token starts: an item, not the list of items it opens, nor the file. */
	private static function findOutermostNode(Token $token): ?Node
	{
		$outermost = null;
		for ($node = $token->parent; $node && !$node instanceof FileNode && $node->getFirstToken() === $token; $node = $node->parent) {
			if (!$node instanceof PlainNodeList && !$node instanceof SeparatedNodeList) {
				$outermost = $node;
			}
		}

		return $outermost;
	}


	private static function getEndLine(Node $node): int
	{
		$token = $node->getLastToken();
		return $token === null || $token->line < 0
			? PHP_INT_MAX
			: $token->line + preg_match_all('~\r\n|\r|\n~', $token->text);
	}
}
