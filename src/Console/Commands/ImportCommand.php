<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Console\Commands;

use DressCode\Console\{CommandContext, Markup};
use DressCode\Interop\{PhpCodeSniffer, PhpCsFixer, Translator};
use Nette\CommandLine\Attributes as Cli;
use function count, sprintf;


/**
 * The command `import`: translates a configuration of PHP CS Fixer or PHP_CodeSniffer and prints it, what it could not
 * translate said on the error output.
 * @internal
 */
final readonly class ImportCommand
{
	public function __construct(
		private CommandContext $context,
	) {
	}


	#[Cli\Command('Translate a PHP CS Fixer or PHP_CodeSniffer configuration')]
	public function import(
		#[Cli\Argument('Configuration file of PHP CS Fixer or PHP_CodeSniffer')]
		string $file,
	): int
	{
		$file = $this->context->resolvePath($file);
		$translator = new Translator;
		if (preg_match('~\.xml(\.dist)?$~Di', $file)) {
			[$rules, $unread] = PhpCodeSniffer::readConfig($file);
			$translation = $translator->translate($rules);
		} else {
			[$rules, $indent, $lineEnding] = PhpCsFixer::readConfig($file);
			$unread = [];
			$translation = $translator->translate($rules, $indent, $lineEnding);
		}

		foreach ($unread as $warning) {
			$translation->warn($warning);
		}

		$this->context->out->write($translation->toPhp());
		$this->context->writeNote(sprintf(
			"\nRead %s; set %s and %s.\n",
			Markup::formatCount(count($rules), 'rule'),
			Markup::formatCount(count($translation->getPaths()), 'decision'),
			Markup::formatCount(count($translation->presets), 'preset'),
		));
		foreach ($translation->warnings as $warning) {
			$this->context->writeNote("  $warning\n");
		}

		$untranslated = array_diff_key(
			['indentation.unit' => 'the indentation', 'file.lineEnding' => 'the line ending'],
			array_flip($translation->getPaths()),
		);
		if (!$translation->presets && $untranslated) {
			$this->context->writeNote(sprintf(
				"  %s %s not translated; set %s with `%s`.\n",
				ucfirst(implode(' and ', $untranslated)),
				count($untranslated) > 1 ? 'are' : 'is',
				count($untranslated) > 1 ? 'them' : 'it',
				implode('` and `', array_keys($untranslated)),
			));
		}

		return 0;
	}
}
