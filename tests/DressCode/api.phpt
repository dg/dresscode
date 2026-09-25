<?php declare(strict_types=1);

/**
 * Every class of DressCode is either part of the public surface or @internal, as the two lists of docs/internals.md
 * say, so that a class never becomes API by omission and the lists never drift from the code.
 */

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/**
 * The names of a list of docs/internals.md: what each item names in front of its colon.
 * @return list<string>  classes and namespaces ending with `\*` under DressCode, files of src/ ending with `.php`
 */
function readList(string $doc, string $title): array
{
	Assert::true((bool) preg_match('~^' . preg_quote($title, '~') . ':\n\n((?:- .*\n)+)~m', $doc, $m), "the list '$title' in docs/internals.md");
	$names = [];
	foreach (explode("\n", trim($m[1])) as $item) {
		$head = explode(':', substr($item, 2), 2)[0];
		preg_match_all('~`([^`]+)`~', $head, $mm);
		array_push($names, ...$mm[1]);
	}

	return $names;
}


/**
 * The classes, interfaces, traits and enums a file declares, each with the doc comment standing above it.
 * @return array<string, string>  fully qualified name => doc comment
 */
function readDeclarations(string $file): array
{
	$result = [];
	$namespace = '';
	$doc = '';
	$tokens = PhpToken::tokenize((string) file_get_contents($file));
	foreach ($tokens as $i => $token) {
		if ($token->is(T_NAMESPACE)) {
			$namespace = $tokens[$i + 2]->text;
		} elseif ($token->is(T_DOC_COMMENT)) {
			$doc = $token->text;
		} elseif (
			$token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
			&& $tokens[$i + 2]->is(T_STRING)
			&& !$tokens[$i - 1]->is(T_DOUBLE_COLON)
		) {
			$result[$namespace . '\\' . $tokens[$i + 2]->text] = $doc;
			$doc = '';
		} elseif (
			!$token->isIgnorable()
			&& !$token->is([T_FINAL, T_ABSTRACT, T_READONLY, T_ATTRIBUTE, ']', T_STRING, '(', ')', ','])
		) {
			$doc = '';
		}
	}

	return $result;
}


/** How specifically a name of a list covers the class declared in the file; null for not at all. */
function matchName(string $name, string $class, string $file): ?int
{
	return match (true) {
		$name === $class => PHP_INT_MAX,
		str_ends_with($name, '.php') => strtr($name, '\\', '/') === $file ? PHP_INT_MAX - 1 : null,
		str_ends_with($name, '\*') => str_starts_with($class, substr($name, 0, -1)) ? strlen($name) : null,
		default => null,
	};
}


$src = __DIR__ . '/../../src';
$doc = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../docs/internals.md'));
$lists = ['public' => readList($doc, 'Public'), 'internal' => readList($doc, 'Internal')];

$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
	if ($file->getExtension() === 'php') {
		$relative = str_replace('\\', '/', substr((string) $file, strlen($src) + 1));
		foreach (readDeclarations((string) $file) as $class => $comment) {
			$classes[substr($class, strlen('DressCode\\'))] = [$relative, str_contains($comment, '@internal')];
		}
	}
}

Assert::true(count($classes) > 100);


test('every class is in one of the lists, and @internal exactly where the internal one has it', function () use ($classes, $lists) {
	$errors = [];
	foreach ($classes as $class => [$file, $tagged]) {
		$best = [];
		foreach ($lists as $kind => $names) {
			foreach ($names as $name) {
				$best[$kind] = max($best[$kind] ?? null, matchName($name, $class, $file));
			}
		}

		$public = $best['public'] ?? null;
		$internal = $best['internal'] ?? null;
		if ($public === null && $internal === null) {
			$errors[] = "$class is in neither list";
		} elseif ($public === $internal) {
			$errors[] = "$class is named by both lists alike";
		} elseif ($public > $internal && $tagged) {
			$errors[] = "$class is public, but marked @internal";
		} elseif ($internal > $public && !$tagged) {
			$errors[] = "$class is internal, but not marked @internal";
		}
	}

	Assert::same([], $errors);
});


test('every name of the lists stands for something in src/', function () use ($classes, $lists) {
	$missing = [];
	foreach (array_merge(...array_values($lists)) as $name) {
		$found = array_filter(
			$classes,
			fn(array $info, string $class) => matchName($name, $class, $info[0]) !== null,
			ARRAY_FILTER_USE_BOTH,
		);
		if (!$found) {
			$missing[] = $name;
		}
	}

	Assert::same([], $missing);
});
