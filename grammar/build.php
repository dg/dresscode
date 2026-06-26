<?php

/**
 * Copied from Latte grammar/rebuildParsers.php (https://latte.nette.org), itself a port of nikic/php-parser grammar/rebuildParsers.php.
 */

require __DIR__ . '/phpyLang.php';

$grammarFileToName = [
	__DIR__ . '/php.y' => 'TagParserData',
];

$tokensFile     = __DIR__ . '/tokens.y';
$tokensTemplate = __DIR__ . '/tokens.template';
$skeletonFile   = __DIR__ . '/parser.template';
$tmpGrammarFile = __DIR__ . '/tmp_parser.phpy';
$tmpResultFile  = __DIR__ . '/tmp_parser.php';
$resultDir = __DIR__ . '/../../src/Latte/Compiler';
$tokensResultsFile = $resultDir . '/Token.php';

$kmyacc = getenv('KMYACC');
if (!$kmyacc) {
	// Use phpyacc from dev dependencies by default.
	$kmyacc = __DIR__ . '/../vendor/ircmaxell/php-yacc/bin/phpyacc';
}

$options = array_flip($argv);
$optionDebug = isset($options['--debug']);
$optionKeepTmpGrammar = isset($options['--keep-tmp-grammar']);

///////////////////
/// Main script ///
///////////////////

$tokens = file_get_contents($tokensFile);

foreach ($grammarFileToName as $grammarFile => $name) {
	echo "Building temporary $name grammar file.\n";

	$grammarCode = file_get_contents($grammarFile);
	$grammarCode = str_replace('%tokens', $tokens, $grammarCode);
	$grammarCode = preprocessGrammar($grammarCode);

	file_put_contents($tmpGrammarFile, $grammarCode);

	$additionalArgs = $optionDebug ? '-t -v' : '';

	echo "Building $name parser.\n";
	$output = execCmd("$kmyacc $additionalArgs -m $skeletonFile -p $name $tmpGrammarFile");

	$resultCode = file_get_contents($tmpResultFile);
	$resultCode = removeTrailingWhitespace($resultCode);
	$resultCode = optimize($resultCode);

	ensureDirExists($resultDir);
	file_put_contents("$resultDir/$name.php", $resultCode);
	unlink($tmpResultFile);

	echo "Building token definition.\n";
	$output = execCmd("$kmyacc -m $tokensTemplate $tmpGrammarFile");
	$code = file_get_contents($tmpResultFile);
	$code = preg_replace_callback('~T_(\w+)~', fn($m) => "Php_" . str_replace('_', '', ucwords(strtolower($m[1]), '_')), $code);
	$code = strtr($code, [
		'ByVarOrVararg' => '',
		'Php_Lnumber' => 'Php_Integer',
		'Php_Dnumber' => 'Php_Float',
	]);
	file_put_contents($tmpResultFile, $code);
	rename($tmpResultFile, $tokensResultsFile);

	if (!$optionKeepTmpGrammar) {
		unlink($tmpGrammarFile);
	}
}

////////////////////////////////
/// Utility helper functions ///
////////////////////////////////

function ensureDirExists($dir)
{
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
}


function execCmd($cmd)
{
	$output = trim((string) shell_exec(PHP_BINARY . " $cmd 2>&1"));
	if ($output !== '') {
		echo '> ' . $cmd . "\n";
		echo $output;
	}
	return $output;
}
