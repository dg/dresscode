<?php declare(strict_types=1);

/**
 * Random damage to the indentation, the blank lines, the line breaks and the spaces of the corpus, over the
 * whole rule set. The fixed file is the reference, not the original one: the corpus is foreign code that no
 * preset accepts, while its fixed form is a fixpoint the fixer must return to from any whitespace around it.
 */

use DressCode\Analyses;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\Diff;
use DressCode\Engine\FileProcessor;
use DressCode\Engine\Gaps\Resolver;
use DressCode\Line;
use DressCode\PresetContext;
use DressCode\Presets\Per;
use DressCode\Testing\GapSurvey;
use PhpSyntax\Nodes\Expression\ShellExecNode;
use PhpSyntax\Nodes\FileNode;
use PhpSyntax\Nodes\Scalar\HeredocNode;
use PhpSyntax\Nodes\Scalar\InterpolatedStringNode;
use PhpSyntax\Parser;
use PhpSyntax\Style;
use PhpSyntax\Token;
use PhpSyntax\TokenKind;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


/**
 * Where the indentation of the code may be damaged: every line that has one, whatever stands on it.
 * @return list<int>
 */
function candidateLines(FileNode $file, string $code): array
{
	$lines = explode("\n", $code);
	$starts = [];
	$offset = 0;
	foreach ($lines as $i => $text) {
		$starts[$i + 1] = $offset;
		$offset += strlen($text) + 1;
	}

	$indentable = [];
	for ($token = $file->getFirstToken(); $token !== null; $token = $token->getNext()) {
		// the line ending that opens the line has to be trivia: trailing trivia of the token before,
		// or leading trivia of this one when several lines pass. Where the previous token ends with
		// a line ending in its own text instead, what looks like indentation is text and means
		// something: a heredoc body and its closing marker, inline HTML, __halt_compiler() data;
		// and a line ending inside string interpolation opens a line of the string, not of the code
		$previous = $token->getPrevious();
		$before = $previous === null ? [] : $previous->trailingTrivia;
		$last = $before[count($before) - 1] ?? null;
		$opens = $last !== null && $last->isEndOfLine() && !$last->inInterpolation;
		foreach ($token->leadingTrivia as $trivia) {
			$opens = $opens || ($trivia->isEndOfLine() && !$trivia->inInterpolation);
			// a comment opening a line has an indentation of its own; its later lines are its text
			$at = $trivia->isComment() && !$trivia->inInterpolation ? $trivia->originalLine : null;
			if ($at !== null && preg_match('~^[ \t]*+/~', $lines[$at - 1] ?? '')) {
				$indentable[] = $at;
			}
		}

		$line = $token->getLine();
		$offset = $token->getOffset();
		if (
			$opens
			&& $line !== null
			&& $offset !== null
			&& preg_match('~^[ \t]*+$~D', substr($code, $starts[$line], $offset - $starts[$line]))
		) {
			$indentable[] = $line;
		}
	}

	$indentable = array_values(array_unique($indentable));
	sort($indentable);
	return $indentable;
}


/**
 * Where the blank lines and the line breaks of the code may be damaged: what the engine of the gap rules
 * says some rule governs, so that every damage is one a rule will undo. Blank lines are damaged above the
 * line the governed run stands above (the token's line, or the line of the comment above it) and only
 * out of the range the rules allow; a required line break is damaged by joining the line with the one
 * above, a forbidden one by splitting the line before the token.
 * @return array{list<array{int, int, ?int, int}>, list<int>, list<int>}  blank lines as line, min, max, found; lines whose break is required; offsets of the tokens whose break is forbidden
 */
function candidateGaps(Resolver $gaps, FileNode $file, Style $style): array
{
	$blank = $breaks = $splits = [];
	foreach (GapSurvey::collect($gaps, $file, $style) as $gap) {
		$line = $gap[1]->getLine();
		$offset = $gap[1]->getOffset();
		if ($line === null || $offset === null) {
			continue;
		}

		if ($gap[0] === 'blank') {
			$blank[] = [$gap[4]->originalLine ?? $line, $gap[2][0], $gap[2][1], $gap[3]];
		} elseif ($gap[0] === 'line' && $gap[2] === Line::Next && !$gap[1]->hasComment()) {
			// a comment above the token would end up on its line, where a comment may stand on purpose
			$breaks[] = $line;
		} elseif ($gap[0] === 'line' && $gap[2] === Line::Same) {
			$splits[] = $offset;
		}
	}

	return [$blank, $breaks, $splits];
}


/**
 * Where the space between two tokens of one line may be damaged. Token::getTrailingSpace() answers null
 * wherever a line ending or a comment is in the way, but inside a string it answers with the empty space
 * that separates nothing, and there the whitespace is the value; a token under a string is left out for
 * that reason. Taking a space away can weld two tokens into one (`- -` into `--`), so each place also
 * says whether it may be closed and not only widened.
 * @return list<array{int, string, bool}>  offset of the space, the space, whether it may be closed
 */
function candidateSpaces(FileNode $file): array
{
	$spaces = [];
	for ($token = $file->getFirstToken(); $token !== null; $token = $token->getNext()) {
		$space = $token->getTrailingSpace();
		$next = $token->getNext();
		$offset = $token->getOffset();
		// and what a close tag, inline HTML or the data after __halt_compiler() has in front of it is
		// printed, so a space put there is output and not layout
		if (
			$space === null
			|| $next === null
			|| $offset === null
			|| insideString($token)
			|| insideString($next)
			|| $token->is(TokenKind::InlineHtml, TokenKind::CloseTag, TokenKind::HaltCompilerData)
			|| $next->is(TokenKind::InlineHtml, TokenKind::CloseTag, TokenKind::HaltCompilerData)
		) {
			continue;
		}

		$welds = count(@token_get_all("<?php {$token->text}{$next->text}")) !== 3;
		$spaces[] = [$offset + strlen($token->text), $space, !$welds];
	}

	return $spaces;
}


/** Whether the token stands inside a string, where whitespace is part of the value. */
function insideString(Token $token): bool
{
	for ($node = $token->parent; $node !== null; $node = $node->parent) {
		if ($node instanceof HeredocNode || $node instanceof InterpolatedStringNode || $node instanceof ShellExecNode) {
			return true;
		}
	}

	return false;
}


/**
 * Damages the indentation of some lines, the blank lines above others, the line break above a few or inside
 * a few, and the space between tokens of a few. The spaces go first and by offset, before the text is cut
 * into lines; none of them adds or drops a line, so the line numbers the tree gave still hold afterwards,
 * and the place of a split is carried along with them.
 * @param  list<int>  $indentable
 * @param  list<array{int, int, ?int, int}>  $blankable
 * @param  list<int>  $breakable
 * @param  list<int>  $splittable
 * @param  list<array{int, string, bool}>  $spaces
 * @return array{string, list<int>}  the damaged code and the lines of the original it was done to
 */
function damage(
	string $code,
	array $indentable,
	array $blankable,
	array $breakable,
	array $splittable,
	array $spaces,
	Randomizer $randomizer,
): array
{
	$split = array_slice($randomizer->shuffleArray($splittable), 0, 1)[0] ?? null;
	foreach (array_reverse(pickSpaces($spaces, 3, $randomizer)) as [$offset, $space, $closable]) {
		$replacement = match ($closable ? $randomizer->getInt(0, 2) : $randomizer->getInt(0, 1)) {
			0 => $space . str_repeat(' ', $randomizer->getInt(1, 3)),
			1 => "\t",
			default => '',
		};
		$code = substr($code, 0, $offset) . $replacement . substr($code, $offset + strlen($space));
		if ($split !== null && $offset < $split) {
			$split += strlen($replacement) - strlen($space);
		}
	}

	$lines = explode("\n", $code);
	$picked = [];
	foreach (array_slice($randomizer->shuffleArray($indentable), 0, 3) as $line) {
		$picked[$line] = ['indent'];
	}

	foreach (array_slice($randomizer->shuffleArray($blankable), 0, 3) as [$line, $min, $max, $found]) {
		$picked[$line] = ['blank', $min, $max, $found];
	}

	foreach (array_slice($randomizer->shuffleArray($breakable), 0, 1) as $line) {
		$picked[$line] = ['break'];
	}

	if ($split !== null) {
		$start = strrpos(substr($code, 0, $split), "\n");
		$picked[substr_count($code, "\n", 0, $split) + 1] = ['split', $split - ($start === false ? -1 : $start)];
	}

	ksort($picked);
	$previous = -2;
	foreach ($picked as $line => $what) { // a gap keeps one mutation out of the reach of the next
		if ($line <= $previous + 1) {
			unset($picked[$line]);
		} else {
			$previous = $line;
		}
	}

	// from the bottom up, so that the lines a mutation adds or drops do not move the ones still to come
	foreach (array_reverse($picked, preserve_keys: true) as $line => $what) {
		$done = match ($what[0]) {
			'blank' => blankLines($lines, $line, $what[1], $what[2], $what[3], $randomizer),
			'break' => joinLines($lines, $line),
			'split' => splitLine($lines, $line, $what[1]),
			default => $lines[$line - 1] = indent($lines[$line - 1], $randomizer),
		};
		if ($done === false) {
			unset($picked[$line]);
		}
	}

	return [implode("\n", $lines), array_keys($picked)];
}


/**
 * Draws places to damage the space at, in the order of the text so that they can be applied from the end.
 * @param  list<array{int, string, bool}>  $spaces
 * @return list<array{int, string, bool}>
 */
function pickSpaces(array $spaces, int $count, Randomizer $randomizer): array
{
	$picked = array_slice($randomizer->shuffleArray($spaces), 0, $count);
	usort($picked, fn(array $a, array $b) => $a[0] <=> $b[0]);
	return $picked;
}


/** Returns the line with its indentation rewritten to one that is wrong in some way. */
function indent(string $line, Randomizer $randomizer): string
{
	$width = strspn($line, " \t");
	$indentation = substr($line, 0, $width);
	$level = intdiv(strlen(str_replace("\t", '    ', $indentation)), 4);
	$damaged = match ($randomizer->getInt(0, 2)) {
		0 => str_repeat('    ', $level + $randomizer->getInt(1, 3)),
		1 => str_repeat('    ', max(0, $level - $randomizer->getInt(1, 3))),
		default => str_repeat("\t", $level),
	};
	// at level zero there is nothing to remove and nothing to write with tabs
	return ($damaged === $indentation ? '    ' : $damaged) . substr($line, $width);
}


/**
 * Takes away the blank lines above the line, or adds enough for the count to leave the range the rules
 * allow; false when the range allows either.
 * @param array<int, string> $lines
 */
function blankLines(array &$lines, int $line, int $min, ?int $max, int $found, Randomizer $randomizer): bool
{
	$first = $line - 1;
	while ($first > 0 && trim($lines[$first - 1]) === '') {
		$first--;
	}

	$removable = $found > 0 && $min > 0;
	if ($removable && ($max === null || $randomizer->getInt(0, 1) === 0)) {
		array_splice($lines, $first, $line - 1 - $first);
	} elseif ($max !== null) {
		array_splice($lines, $line - 1, 0, array_fill(0, $max - $found + $randomizer->getInt(1, 3), ''));
	} else {
		return false;
	}

	return true;
}


/**
 * Splits the line before the byte column, putting a forbidden line break in; the new line keeps the
 * indentation of the old one. False at the start of the line, where there is nothing to split.
 * @param array<int, string> $lines
 */
function splitLine(array &$lines, int $line, int $column): bool
{
	$text = $lines[$line - 1];
	$head = rtrim(substr($text, 0, $column - 1));
	if ($head === '') {
		return false;
	}

	$indentation = substr($text, 0, strspn($text, " \t"));
	array_splice($lines, $line - 1, 1, [$head, $indentation . substr($text, $column - 1)]);
	return true;
}


/**
 * Joins the line with the one above it, taking the required line break away; false where a line comment
 * above would swallow the code, or where the line above is blank, because the blank lines are governed
 * apart from the break and the join would take one away for good.
 * @param array<int, string> $lines
 */
function joinLines(array &$lines, int $line): bool
{
	$above = $lines[$line - 2] ?? null;
	if ($above === null || trim($above) === '' || preg_match('~(//|#)~', $above)) {
		return false;
	}

	$lines[$line - 2] = $above . ' ' . ltrim($lines[$line - 1]);
	array_splice($lines, $line - 1, 1);
	return true;
}


/**
 * The tokens of the code without whitespace: an oracle outside DressCode for "the meaning has not changed".
 * @return list<array{int|string, string}>
 */
function meaning(string $code): array
{
	$tokens = [];
	foreach (@token_get_all($code) as $token) { // an escape sequence of the code warns, and warns for both alike
		if (!is_array($token)) {
			$tokens[] = [$token, $token];
		} elseif ($token[0] !== T_WHITESPACE) {
			$tokens[] = [token_name($token[0]), $token[1]];
		}
	}

	return $tokens;
}


/** The head of a diff; a whole one of a large file takes the test runner longer to format than to produce. */
function excerpt(string $diff, int $lines = 24): string
{
	$head = explode("\n", $diff, $lines + 1);
	return count($head) > $lines
		? implode("\n", array_slice($head, 0, $lines)) . "\n...\n"
		: $diff;
}


/** @return list<string> */
function findFiles(string $dir): array
{
	$files = [];
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if (preg_match('~\.(php|phpt|inc)$~', $file->getFilename())) {
			$files[] = str_replace('\\', '/', $file->getPathname());
		}
	}

	sort($files);
	return $files;
}


// the damage is random but reproducible; DRESSCODE_FUZZ_SEED=<n> draws a different set of it
$seed = (int) (getenv('DRESSCODE_FUZZ_SEED') ?: 1);
$registry = new RuleRegistry;
$resolver = new PresetResolver($registry);
$config = Config::create()
	->preset(Per::class)
	// PER says nothing about blank lines, and a range as a count would leave the fixpoint ambiguous
	->enable('dresscode/declaration-blank-lines', [
		'betweenFunctions' => 2, 'betweenFunctionsInInterface' => 1, 'beforeFirst' => 0, 'afterLast' => 0,
		'afterOpeningBrace' => 0, 'beforeClosingBrace' => 0, 'betweenTraitUses' => 0, 'afterTraitUses' => 1,
		'betweenMembers' => 1, 'beforeDocumentedMember' => 1, 'afterPhpDoc' => 0,
	])
	->enable('dresscode/statement-blank-lines', ['before' => ['return' => 1]])
	->enable('dresscode/body-blank-lines', ['beforeClosingBrace' => true]);
// PER leaves some gaps to taste and some to nobody; the damage can only be undone where a rule governs
// the gap exactly, so every spacing rule of the catalogue is on, with its exact choice where it has one
foreach (array_keys($registry->getRules()) as $name) {
	if (str_ends_with($name, '-spacing')) {
		$config->enable($name, match ($name) {
			'dresscode/binary-operator-spacing' => ['spacing' => 'single'],
			'dresscode/ternary-operator-spacing' => ['spacing' => 'single'],
			'dresscode/comma-spacing' => ['tabAlignment' => false],
			'dresscode/comment-spacing' => ['before' => 'single'],
			default => true,
		});
	}
}

$rules = $resolver->resolve($config, new PresetContext(Config::DefaultPhpVersion));
$gaps = new Resolver($rules);
$style = new Style('    ', "\n");
$processor = new FileProcessor(
	$rules,
	new Analyses\Registry,
	$registry->resolveNames(...),
	Config::DefaultPhpVersion,
	$style,
	detectEol: false, // one line ending for the whole corpus, so that the damage is written the same way everywhere
	strict: true, // a fix without a report is a broken contract, and over foreign code is where it would hide
);
$parser = new Parser;
$files = findFiles(__DIR__ . '/../../corpus');
Assert::true(count($files) > 100);
// a run over the whole corpus takes a while, so a draw of it stands for the corpus; DRESSCODE_FUZZ_ALL=1 takes all
if (!getenv('DRESSCODE_FUZZ_ALL')) {
	$files = array_slice(new Randomizer(new Mt19937($seed))->shuffleArray($files), 0, 25);
	sort($files);
}

// every file is reported, so that one broken rule does not hide the rest
$failures = [];
foreach ($files as $file) {
	$where = substr($file, strrpos($file, '/corpus/') + 8);
	$fixed = $processor->process($file, (string) file_get_contents($file));
	if ($fixed->output === null || $fixed->warnings) {
		$failures[] = "$where: " . ($fixed->error ?? implode(' ', $fixed->warnings));
		continue;
	}

	$canonical = $fixed->output;
	$randomizer = new Randomizer(new Mt19937($seed + crc32($where)));
	$tree = $parser->parse($canonical);
	[$blankable, $breakable, $splittable] = candidateGaps($gaps, $tree, $style);
	[$damaged, $lines] = damage($canonical, candidateLines($tree, $canonical), $blankable, $breakable, $splittable, candidateSpaces($tree), $randomizer);
	$what = "$where: the damage to lines " . implode(', ', $lines) . ' of its fixed form';
	if (meaning($canonical) !== meaning($damaged)) {
		$failures[] = "$what changed the code";
		continue;
	}

	$result = $processor->process($file, $damaged);
	if ($result->output === null || $result->warnings) {
		$failures[] = "$what: " . ($result->error ?? implode(' ', $result->warnings));
	} elseif ($result->output !== $canonical) {
		// the damage and what is left of it, so that the failure can be read without running it again
		$failures[] = "$what was not fixed back.\nThe damage:\n"
			. excerpt(Diff::unified($canonical, $damaged, $where))
			. "What the fixer left of it:\n"
			. excerpt(Diff::unified($canonical, $result->output, $where));
	}
}

Assert::same([], $failures, "seed $seed");
