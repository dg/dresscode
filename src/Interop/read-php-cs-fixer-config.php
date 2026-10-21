<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

// Run by PhpCsFixer::readConfig() in a process of its own, so that the configuration it executes cannot write into the
// output of DressCode, change its directory or end it: the autoload of the project first, then the file, whose rules,
// indent and line ending, or its error, go as JSON into the file given.
[, $file, $result] = $_SERVER['argv'];
for ($dir = dirname($file);; $dir = dirname($dir)) {
	if (is_file("$dir/vendor/autoload.php")) {
		require "$dir/vendor/autoload.php";
		break;
	} elseif (dirname($dir) === $dir) {
		break;
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
	$data = ['error' => $e->getMessage(), 'missingClass' => (bool) preg_match('~^Class ".+" not found$~', $e->getMessage())];
}

file_put_contents($result, json_encode($data, JSON_THROW_ON_ERROR));
