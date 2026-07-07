<?php declare(strict_types=1);

namespace DressCode\Reporters;

use DressCode\Engine\FileResult;
use DressCode\Engine\RunResult;
use DressCode\Reporter;
use DressCode\Severity;


/**
 * Checkstyle XML, understood by CI systems and editors.
 */
final class CheckstyleReporter implements Reporter
{
	/** @var resource */
	private $stream;


	/** @param ?resource $stream */
	public function __construct($stream = null)
	{
		$this->stream = $stream ?? STDOUT;
	}


	public function start(int $fileCount, bool $fix): void
	{
		fwrite($this->stream, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<checkstyle version=\"1.0\">\n");
	}


	public function reportFile(FileResult $result): void
	{
		if (!$result->violations && $result->error === null) {
			return;
		}

		$xml = sprintf("  <file name=\"%s\">\n", self::escape($result->path));
		if ($result->error !== null) {
			$xml .= sprintf(
				"    <error line=\"%d\" severity=\"error\" message=\"%s\" source=\"syntax\"/>\n",
				$result->errorLine ?? 1,
				self::escape($result->error),
			);
		}

		foreach ($result->violations as $violation) {
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
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}
