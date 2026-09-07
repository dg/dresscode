<?php declare(strict_types=1);

/**
 * The rules that rewrite names and imports must leave every name meaning what it meant: over the corpus,
 * what NameResolver answers about each name outside the imports is the same before and after the fix.
 * A rule that moves an import item into a statement writing its items differently, or drops an import
 * something still uses, leaves code that parses and round-trips, so only the meaning of the names says so.
 */

use DressCode\Analyses;
use DressCode\Config;
use DressCode\Config\PresetResolver;
use DressCode\Config\RuleRegistry;
use DressCode\Engine\Diff;
use DressCode\Engine\FileProcessor;
use DressCode\Rules;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\Nodes\NameNode;
use PhpSyntax\Parser;
use PhpSyntax\Style;
use PhpSyntax\SymbolKind;
use Tester\Assert;


require __DIR__ . '/../../bootstrap.php';


/**
 * What every name of the file stands for, in the order the names are written. The names of the imports and
 * of the namespace are left out: adding and removing an import is what these rules are for, and what such
 * an import means is said by the names that use it.
 * @return list<string>
 */
function meanings(string $code): array
{
	$file = new Parser()->parse($code);
	$resolver = new NameResolver($file);
	$meanings = [];
	foreach ($file->find(NameNode::class) as $name) {
		if ($name->isDeclaration()) {
			continue;
		}

		// a class and a function are case-insensitive, a constant is not
		$meanings[] = match ($name->role) {
			SymbolKind::Function => 'function ' . strtolower($resolver->resolveFunction($name)),
			SymbolKind::Constant => 'const ' . $resolver->resolveConstant($name),
			SymbolKind::ClassLike => strtolower($resolver->resolveClass($name)),
		};
	}

	return $meanings;
}


/** @return list<string> */
function corpusFiles(string $dir): array
{
	$files = [];
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
		if (preg_match('~\.(php|phpt|inc)$~', $file->getFilename())) {
			$files[] = str_replace('\\', '/', $file->getPathname());
		}
	}

	sort($files);
	return $files;
}


// every rule that writes a name or an import, together, so that one undoing another shows up as well
$rules = [];
foreach ([
	Rules\Namespaces\GlobalImportsRule::class => ['functions' => 'all', 'constants' => 'all'],
	Rules\Namespaces\ImportNotationRule::class => ['classes' => 'combined', 'groupUse' => 'expand'],
	Rules\Namespaces\NoLeadingBackslashInImportRule::class => true,
	Rules\Namespaces\OrderedImportsRule::class => true,
	Rules\Namespaces\ReferenceUsedNamesOnlyRule::class => true,
	Rules\Namespaces\UnusedImportsRule::class => true,
	Rules\Namespaces\UseFromSameNamespaceRule::class => true,
	Rules\Namespaces\UselessAliasRule::class => true,
] as $class => $options) {
	$rules[] = PresetResolver::createRule($class, $options);
}

$registry = new RuleRegistry;
$processor = new FileProcessor($rules, new Analyses\Registry, $registry->resolveNames(...), Config::DefaultPhpVersion, new Style("\t", "\n"));

$files = corpusFiles(__DIR__ . '/../../corpus');
Assert::true(count($files) > 50);

// every file is reported, so that one broken rule does not hide the rest
$failures = [];
foreach ($files as $file) {
	$where = substr($file, strrpos($file, '/corpus/') + 8);
	$code = (string) file_get_contents($file);
	$result = $processor->process($file, $code);
	if ($result->output === null) {
		$failures[] = "$where: " . $result->error;
		continue;
	}

	$before = meanings($code);
	$after = meanings($result->output);
	if ($before !== $after) {
		$failures[] = "$where: the names mean something else after the fix.\n"
			. Diff::unified(implode("\n", $before) . "\n", implode("\n", $after) . "\n", $where);
	}
}

Assert::same([], $failures);
