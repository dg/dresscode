<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Reporters;

use DressCode\Engine\{RunInfo, RunResult};
use DressCode\{FileResult, Reporter, Severity};
use function sprintf;
use const ENT_QUOTES, ENT_SUBSTITUTE, ENT_XML1;


/**
 * Checkstyle XML, understood by CI systems and editors.
 * @internal
 */
final class CheckstyleReporter implements Reporter
{
	/** @var resource */
	private $stream;

	private bool $fix = false;


	/** @param ?resource $stream */
	public function __construct($stream = null)
	{
		$this->stream = $stream ?? STDOUT;
	}


	public function start(RunInfo $run): void
	{
		$this->fix = $run->fix;
		fwrite($this->stream, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<checkstyle version=\"1.0\">\n");
	}


	public function reportFile(FileResult $result): void
	{
		$violations = $this->fix ? $result->remaining : $result->violations; // in a fix, positioned in the fixed text
		if (!$violations && $result->syntaxError === null && $result->failure === null) {
			return;
		}

		$xml = sprintf("  <file name=\"%s\">\n", self::escape($result->path));
		if ($result->failure !== null) {
			$xml .= sprintf("    <error line=\"1\" severity=\"error\" message=\"%s\" source=\"dresscode\"/>\n", self::escape($result->failure));
		}

		if ($result->syntaxError !== null) {
			$xml .= sprintf(
				"    <error line=\"%d\" severity=\"error\" message=\"%s\" source=\"syntax\"/>\n",
				$result->syntaxErrorLine ?? 1,
				self::escape($result->syntaxError),
			);
		}

		foreach ($violations as $violation) {
			$xml .= sprintf(
				"    <error line=\"%d\"%s severity=\"%s\" message=\"%s\" source=\"%s\"/>\n",
				$violation->line,
				$violation->column === null ? '' : " column=\"$violation->column\"",
				$violation->severity === Severity::Error ? 'error' : 'warning',
				self::escape($violation->message),
				self::escape($violation->ruleName),
			);
		}

		fwrite($this->stream, $xml . "  </file>\n");
	}


	public function finish(RunResult $result): void
	{
		fwrite($this->stream, "</checkstyle>\n");
	}


	private static function escape(string $text): string
	{
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
