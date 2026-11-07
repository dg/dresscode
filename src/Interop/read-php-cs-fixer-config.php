<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

// Run by PhpCsFixer::readConfig() in a process of its own, so that the configuration it executes cannot write into the
// output of DressCode, change its directory or end it: the autoload of the project and PHP CS Fixer first, then the
// file, whose rules, indent and line ending, or its error, go as JSON into the file given.
[, $file, $result] = $_SERVER['argv'];
$root = dirname($file);
for ($dir = $root;; $dir = dirname($dir)) {
	if (is_file("$dir/vendor/autoload.php")) {
		require "$dir/vendor/autoload.php";
		$root = $dir;
		break;
	} elseif (dirname($dir) === $dir) {
		break;
	}
}

// PHP CS Fixer the autoload of the project does not load: installed by phive, by Composer among the tools, by
// bamarni/composer-bin-plugin, or as the shim, a package holding the archive alone
if (!class_exists(PhpCsFixer\Config::class)) {
	foreach ([
		'tools/php-cs-fixer',
		'tools/php-cs-fixer/vendor/autoload.php',
		'tools/vendor/autoload.php',
		'vendor-bin/php-cs-fixer/vendor/autoload.php',
		'vendor/php-cs-fixer/shim/php-cs-fixer.phar',
	] as $path) {
		if (is_file($path = "$root/$path")) {
			if (!str_ends_with($path, '.php')) { // an archive, which phive names without the extension PHP knows one by
				try {
					Phar::loadPhar($path);
				} catch (PharException) { // a script running it some other way, in a container
					continue;
				}

				$path = "phar://$path/vendor/autoload.php";
			}

			require $path;
			break;
		}
	}
}

chdir(dirname($file));
try {
	$config = require $file;
	$data = match (true) {
		is_array($config) => ['rules' => $config, 'indent' => '    ', 'lineEnding' => "\n"],
		is_object($config) && method_exists($config, 'getRules') => [
			'rules' => $config->getRules(),
			'indent' => method_exists($config, 'getIndent') ? $config->getIndent() : '    ',
			'lineEnding' => method_exists($config, 'getLineEnding') ? $config->getLineEnding() : "\n",
		],
		default => ['error' => 'none'],
	};
} catch (Throwable $e) {
	$data = [
		'error' => $e->getMessage(),
		'missingFixer' => !class_exists(PhpCsFixer\Config::class) && preg_match('~^Class ".+" not found$~', $e->getMessage()),
	];
}

file_put_contents($result, json_encode($data, JSON_THROW_ON_ERROR));
